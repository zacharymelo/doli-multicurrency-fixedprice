<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    ajax/debug.php
 * \ingroup fixedprice
 * \brief   Comprehensive debug diagnostics for the fixedprice module.
 *          Gated by admin permission + FIXEDPRICE_DEBUG_MODE setting.
 *
 * Modes (via ?mode=):
 *   overview    — Module config, table health, currency rates (default)
 *   prices      — All fixed prices with divergence calculations
 *   settings    — All FIXEDPRICE_* constants from llx_const
 *   sql         — Run a read-only diagnostic query (?mode=sql&q=SELECT...)
 *   hooks       — Show registered hook contexts and verify our hooks fire
 *   all         — Run every diagnostic at once
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}

if (!$user->admin) {
	http_response_code(403);
	print 'Admin only';
	exit;
}
if (!getDolGlobalInt('FIXEDPRICE_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode not enabled. Go to Fixedprice > Setup and enable Debug Mode.';
	exit;
}

header('Content-Type: text/plain; charset=utf-8');

$mode = GETPOST('mode', 'alpha') ?: 'overview';
$run_all = ($mode === 'all');

$MODULE_NAME = 'fixedprice';
$MODULE_UPPER = 'FIXEDPRICE';

print "=== FIXEDPRICE DEBUG DIAGNOSTICS ===\n";
print "Timestamp: ".date('Y-m-d H:i:s T')."\n";
print "Dolibarr: ".(defined('DOL_VERSION') ? DOL_VERSION : 'unknown')."\n";
print "Base currency: ".$conf->currency."\n";
print "Mode: $mode\n";
print "Usage: ?mode=overview|prices|settings|sql|hooks|all\n";
print str_repeat('=', 60)."\n\n";


// =====================================================================
// OVERVIEW
// =====================================================================
if ($mode === 'overview' || $run_all) {
	print "--- MODULE STATUS ---\n";
	print "isModEnabled('fixedprice'): ".(isModEnabled('fixedprice') ? 'YES' : 'NO')."\n";

	// DB table health
	print "\n--- DATABASE TABLES ---\n";
	$tables = array('product_fixed_price');
	foreach ($tables as $tbl) {
		$sql = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX.$tbl;
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			print "  llx_$tbl: ".$obj->cnt." rows\n";
		} else {
			print "  llx_$tbl: TABLE MISSING OR ERROR\n";
		}
	}

	// Active multicurrencies
	print "\n--- ACTIVE MULTICURRENCIES ---\n";
	$sql = "SELECT mc.code, mc.name, mcr.rate";
	$sql .= " FROM ".MAIN_DB_PREFIX."multicurrency mc";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."multicurrency_rate mcr ON mcr.fk_multicurrency = mc.rowid";
	$sql .= " AND mcr.date_sync = (SELECT MAX(date_sync) FROM ".MAIN_DB_PREFIX."multicurrency_rate WHERE fk_multicurrency = mc.rowid)";
	$sql .= " WHERE mc.entity = ".((int) $conf->entity);
	$sql .= " ORDER BY mc.code";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$is_base = ($obj->code == $conf->currency) ? ' (BASE)' : '';
			print "  ".$obj->code." (".$obj->name.") rate=".$obj->rate.$is_base."\n";
		}
	}

	// Hook registration
	print "\n--- HOOK CONTEXTS ---\n";
	if (isset($conf->modules_parts['hooks'])) {
		// Structure is module => [contexts], not context => [modules]
		if (isset($conf->modules_parts['hooks'][$MODULE_NAME])) {
			$hooks = $conf->modules_parts['hooks'][$MODULE_NAME];
			if (is_array($hooks)) {
				foreach ($hooks as $ctx) {
					print "  context='$ctx' module='$MODULE_NAME'\n";
				}
			} else {
				print "  contexts='$hooks' module='$MODULE_NAME'\n";
			}
		} else {
			print "  (module '$MODULE_NAME' NOT found in modules_parts['hooks'])\n";
			print "  Registered modules: ".implode(', ', array_keys($conf->modules_parts['hooks']))."\n";
		}
	} else {
		print "  (modules_parts['hooks'] not set)\n";
	}

	print "\n";
}


// =====================================================================
// PRICES — all fixed prices with divergence
// =====================================================================
if ($mode === 'prices' || $run_all) {
	print "--- ALL FIXED PRICES ---\n";

	require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
	dol_include_once('/fixedprice/lib/fixedprice.lib.php');

	$sql = "SELECT pfp.rowid, pfp.fk_product, pfp.multicurrency_code,";
	$sql .= " pfp.fixed_price_ht, pfp.enabled, pfp.divergence_threshold,";
	$sql .= " p.ref, p.label, p.price as base_price";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_fixed_price pfp";
	$sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pfp.fk_product";
	$sql .= " WHERE pfp.entity = ".((int) $conf->entity);
	$sql .= " ORDER BY p.ref, pfp.multicurrency_code";

	$resql = $db->query($sql);
	if ($resql) {
		print sprintf("  %-15s %-5s %-12s %-12s %-10s %-10s %-8s %-10s\n",
			'Product', 'Curr', 'Fixed', 'Auto', 'Diverg%', 'Thresh%', 'Enabled', 'ThreshSrc');
		print "  ".str_repeat('-', 90)."\n";

		while ($obj = $db->fetch_object($resql)) {
			$listrate = MultiCurrency::getIdAndTxFromCode($db, $obj->multicurrency_code);
			$rate = !empty($listrate[1]) ? (float) $listrate[1] : 0;
			$auto_price = (float) $obj->base_price * $rate;
			$divergence = fixedpriceCalcDivergence($obj->fixed_price_ht, $auto_price);
			$threshold = fixedpriceResolveThreshold($db, $obj->fk_product, $obj->multicurrency_code);

			// Determine threshold source
			$thresh_src = 'global';
			if ($obj->divergence_threshold !== null && $obj->divergence_threshold !== '') {
				$thresh_src = 'product';
			} else {
				$sql2 = "SELECT pfp2.divergence_threshold";
				$sql2 .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination pac";
				$sql2 .= " JOIN ".MAIN_DB_PREFIX."product_fixed_price pfp2 ON pfp2.fk_product = pac.fk_product_parent";
				$sql2 .= " WHERE pac.fk_product_child = ".((int) $obj->fk_product);
				$sql2 .= " AND pfp2.multicurrency_code = '".$db->escape($obj->multicurrency_code)."'";
				$sql2 .= " AND pfp2.entity = ".((int) $conf->entity);
				$res2 = $db->query($sql2);
				if ($res2 && $db->num_rows($res2) > 0) {
					$pobj = $db->fetch_object($res2);
					if ($pobj->divergence_threshold !== null && $pobj->divergence_threshold !== '') {
						$thresh_src = 'parent';
					}
				}
			}

			$alert = ($divergence > $threshold) ? ' !!!' : '';

			print sprintf("  %-15s %-5s %-12s %-12s %-10s %-10s %-8s %-10s%s\n",
				$obj->ref,
				$obj->multicurrency_code,
				number_format($obj->fixed_price_ht, 2),
				number_format($auto_price, 2),
				number_format($divergence, 1).'%',
				number_format($threshold, 1).'%',
				$obj->enabled ? 'YES' : 'NO',
				$thresh_src,
				$alert
			);
		}
	}
	print "\n";
}


// =====================================================================
// SETTINGS
// =====================================================================
if ($mode === 'settings' || $run_all) {
	print "--- FIXEDPRICE SETTINGS ---\n";

	$sql = "SELECT name, value FROM ".MAIN_DB_PREFIX."const";
	$sql .= " WHERE name LIKE 'FIXEDPRICE%'";
	$sql .= " AND entity IN (0, ".((int) $conf->entity).")";
	$sql .= " ORDER BY name";
	$resql = $db->query($sql);
	if ($resql) {
		while ($row = $db->fetch_object($resql)) {
			print "  $row->name = $row->value\n";
		}
	}
	print "\n";
}


// =====================================================================
// SQL — read-only diagnostic query
// =====================================================================
if ($mode === 'sql') {
	$q = GETPOST('q', 'restricthtml');
	print "--- SQL QUERY ---\n";

	if (empty($q)) {
		print "Usage: ?mode=sql&q=SELECT+rowid,ref+FROM+llx_product_fixed_price+LIMIT+5\n";
		print "\nUseful queries:\n";
		print "  ?mode=sql&q=SELECT rowid,fk_product,multicurrency_code,fixed_price_ht,enabled FROM llx_product_fixed_price ORDER BY rowid DESC LIMIT 10\n";
		print "  ?mode=sql&q=SELECT code,name FROM llx_multicurrency WHERE entity=".((int) $conf->entity)."\n";
	} else {
		$q_trimmed = trim($q);
		if (stripos($q_trimmed, 'SELECT') !== 0) {
			print "ERROR: Only SELECT queries allowed.\n";
		} else {
			$blocked = array('INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'GRANT', 'REVOKE');
			$safe = true;
			foreach ($blocked as $kw) {
				if (stripos($q_trimmed, $kw) !== false && stripos($q_trimmed, $kw) !== stripos($q_trimmed, 'SELECT')) {
					$safe = false;
					break;
				}
			}
			if (!$safe) {
				print "ERROR: Query contains blocked keywords.\n";
			} else {
				if (stripos($q_trimmed, 'LIMIT') === false) {
					$q_trimmed .= ' LIMIT 50';
				}

				print "Query: $q_trimmed\n\n";
				$resql = $db->query($q_trimmed);
				if ($resql) {
					$first = true;
					$row_num = 0;
					while ($obj = $db->fetch_array($resql)) {
						if ($first) {
							print implode("\t", array_keys($obj))."\n";
							print str_repeat('-', 80)."\n";
							$first = false;
						}
						$row_num++;
						$vals = array();
						foreach ($obj as $v) {
							$vals[] = ($v === null) ? 'NULL' : (strlen($v) > 40 ? substr($v, 0, 40).'...' : $v);
						}
						print implode("\t", $vals)."\n";
					}
					print "\n$row_num rows returned.\n";
				} else {
					print "SQL ERROR: ".$db->lasterror()."\n";
				}
			}
		}
	}
	print "\n";
}


// =====================================================================
// HOOKS
// =====================================================================
if ($mode === 'hooks' || $run_all) {
	print "--- HOOK REGISTRATION ---\n";

	print "  Hook contexts from conf->modules_parts['hooks']:\n";
	if (isset($conf->modules_parts['hooks'])) {
		if (isset($conf->modules_parts['hooks'][$MODULE_NAME])) {
			$hooks = $conf->modules_parts['hooks'][$MODULE_NAME];
			if (is_array($hooks)) {
				foreach ($hooks as $ctx) {
					print "    context='$ctx' module='$MODULE_NAME'\n";
				}
			} else {
				print "    contexts='$hooks' module='$MODULE_NAME'\n";
			}
		} else {
			print "    (module '$MODULE_NAME' NOT found in modules_parts['hooks'])\n";
			print "    Registered modules: ".implode(', ', array_keys($conf->modules_parts['hooks']))."\n";
		}
	}

	// Test actions class loading
	print "\n  Actions class:\n";
	$actions_file = DOL_DOCUMENT_ROOT.'/custom/'.$MODULE_NAME.'/class/actions_'.$MODULE_NAME.'.class.php';
	print "    File exists: ".(file_exists($actions_file) ? 'YES' : 'NO')."\n";
	if (file_exists($actions_file)) {
		include_once $actions_file;
		$actions_class = 'ActionsFixedprice';
		print "    Class exists: ".(class_exists($actions_class) ? 'YES' : 'NO')."\n";
		if (class_exists($actions_class)) {
			$methods = array('doActions', 'addMoreActionsButtons');
			foreach ($methods as $m) {
				print "    method $m(): ".(method_exists($actions_class, $m) ? 'defined' : 'MISSING')."\n";
			}
		}
	}
	print "\n";
}


print "=== END DEBUG ===\n";
