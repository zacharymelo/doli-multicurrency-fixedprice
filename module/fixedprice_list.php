<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    fixedprice_list.php
 * \ingroup fixedprice
 * \brief   List all fixed multicurrency prices, pivoted by currency, with inline editing
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/fixedprice/class/fixedprice.class.php');

$langs->loadLangs(array('fixedprice@fixedprice', 'products'));

$action    = GETPOST('action', 'aZ09');
$cancel    = GETPOST('cancel', 'alpha');
$productid = GETPOSTINT('productid');
$lineid    = GETPOSTINT('lineid');

// Sorting
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'p.ref';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'ASC';

// Pagination
$limit  = GETPOSTINT('limit') ?: $conf->liste_limit;
$page   = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

// Filters
$search_ref   = GETPOST('search_ref', 'alpha');
$search_label = GETPOST('search_label', 'alpha');

// Permissions
$permread  = ($user->hasRight('produit', 'lire') || $user->hasRight('service', 'lire'));
$permwrite = ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'));

if (!$permread) {
	accessforbidden();
}

if ($cancel) {
	$action = '';
}

$form = new Form($db);

// --- Get active currencies (excluding base) + their rates ---
$currencies = array();
$rates = array();

$sql_cur = "SELECT mc.code, mc.name FROM ".MAIN_DB_PREFIX."multicurrency mc";
$sql_cur .= " WHERE mc.entity = ".((int) $conf->entity);
$sql_cur .= " AND mc.code != '".$db->escape($conf->currency)."'";
$sql_cur .= " ORDER BY mc.code ASC";
$resql_cur = $db->query($sql_cur);
if ($resql_cur) {
	while ($obj = $db->fetch_object($resql_cur)) {
		$currencies[$obj->code] = $obj->name;

		// Get latest rate
		$sql_rate = "SELECT mcr.rate FROM ".MAIN_DB_PREFIX."multicurrency_rate mcr";
		$sql_rate .= " WHERE mcr.fk_multicurrency = (SELECT rowid FROM ".MAIN_DB_PREFIX."multicurrency WHERE code = '".$db->escape($obj->code)."' AND entity = ".((int) $conf->entity).")";
		$sql_rate .= " ORDER BY mcr.date_sync DESC LIMIT 1";
		$resql_rate = $db->query($sql_rate);
		if ($resql_rate && $db->num_rows($resql_rate) > 0) {
			$rateobj = $db->fetch_object($resql_rate);
			$rates[$obj->code] = (float) $rateobj->rate;
		} else {
			$rates[$obj->code] = 1;
		}
	}
}

/*
 * Actions
 */

// Update all fixed prices for one product row
if ($action == 'updatefixedprice' && $permwrite && $productid > 0) {
	$fpobj = new FixedPrice($db);

	foreach ($currencies as $code => $name) {
		$field_price = GETPOST('fixedprice_ht_'.$code, 'alpha');
		$field_enabled = GETPOST('fixedprice_enabled_'.$code, 'alpha');

		$fp = new FixedPrice($db);
		$exists = $fp->fetchByProductCurrency($productid, $code);

		if ($field_price !== '' && $field_price !== null) {
			// Create or update
			$fp->fk_product = $productid;
			$fp->multicurrency_code = $code;
			$fp->fixed_price_ht = (float) price2num($field_price);
			$fp->price_base_type = 'HT';
			$fp->enabled = ($field_enabled !== null && $field_enabled !== '') ? ((int) $field_enabled) : 1;

			if ($exists > 0) {
				$fp->update($user);
			} else {
				$fp->create($user);
			}
		} elseif ($exists > 0 && ($field_price === '' || $field_price === null)) {
			// Field cleared — delete the fixed price
			$fp->delete($user);
		}
	}

	setEventMessages($langs->trans("FixedPriceSaved"), null, 'mesgs');
	$action = '';
}

// Toggle enabled
if ($action == 'togglefixedprice' && $permwrite && $lineid > 0) {
	$fp = new FixedPrice($db);
	if ($fp->fetch($lineid) > 0) {
		$fp->toggleEnabled($user);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query(array('sortfield' => $sortfield, 'sortorder' => $sortorder, 'page' => $page, 'search_ref' => $search_ref, 'search_label' => $search_label)));
	exit;
}

// Delete
if ($action == 'confirm_delete' && $permwrite && $lineid > 0) {
	$fp = new FixedPrice($db);
	if ($fp->fetch($lineid) > 0) {
		$fp->delete($user);
		setEventMessages($langs->trans("FixedPriceDeleted"), null, 'mesgs');
	}
	$action = '';
}

/*
 * View
 */

$title = $langs->trans('FixedPriceList');
llxHeader('', $title, '');

$threshold_default = (float) getDolGlobalString('FIXEDPRICE_DIVERGENCE_THRESHOLD', '10');

// --- Count total products with fixed prices ---
$sql_count = "SELECT COUNT(DISTINCT pfp.fk_product) as nb";
$sql_count .= " FROM ".MAIN_DB_PREFIX."product_fixed_price pfp";
$sql_count .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pfp.fk_product";
$sql_count .= " WHERE pfp.entity = ".((int) $conf->entity);
if ($search_ref) {
	$sql_count .= natural_search('p.ref', $search_ref);
}
if ($search_label) {
	$sql_count .= natural_search('p.label', $search_label);
}
$resql_count = $db->query($sql_count);
$nbtotalofrecords = 0;
if ($resql_count) {
	$obj = $db->fetch_object($resql_count);
	$nbtotalofrecords = (int) $obj->nb;
}

// --- Fetch product rows ---
$sql = "SELECT DISTINCT p.rowid as product_id, p.ref, p.label, p.price";
$sql .= " FROM ".MAIN_DB_PREFIX."product_fixed_price pfp";
$sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pfp.fk_product";
$sql .= " WHERE pfp.entity = ".((int) $conf->entity);
if ($search_ref) {
	$sql .= natural_search('p.ref', $search_ref);
}
if ($search_label) {
	$sql .= natural_search('p.label', $search_label);
}
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$num = $db->num_rows($resql);

// Page params for URLs
$param = '';
if ($search_ref) {
	$param .= '&search_ref='.urlencode($search_ref);
}
if ($search_label) {
	$param .= '&search_label='.urlencode($search_label);
}

print_barre_liste(
	$title,
	$page,
	$_SERVER['PHP_SELF'],
	$param,
	$sortfield,
	$sortorder,
	'',
	$num,
	$nbtotalofrecords,
	'multicurrency',
	0,
	'',
	'',
	$limit
);

// Delete confirmation dialog
if ($action == 'deletefixedprice' && $lineid > 0) {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?lineid='.$lineid.$param,
		$langs->trans('Delete'),
		$langs->trans('ConfirmDeleteFixedPrice'),
		'confirm_delete',
		'',
		0,
		1
	);
}

// Divergence CSS
print '<style>';
print '.fixedprice-divergence-green { color: #28a745; font-weight: bold; }';
print '.fixedprice-divergence-amber { color: #ffc107; font-weight: bold; }';
print '.fixedprice-divergence-red { color: #dc3545; font-weight: bold; }';
print '</style>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste listwithfilterbefore centpercent">';

// --- Header row ---
print '<tr class="liste_titre">';
print getTitleFieldOfList($langs->trans('ProductRef'), 0, $_SERVER['PHP_SELF'], 'p.ref', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('Label'), 0, $_SERVER['PHP_SELF'], 'p.label', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('BasePrice').' ('.$conf->currency.')', 0, $_SERVER['PHP_SELF'], 'p.price', '', $param, 'class="right nowraponall"', $sortfield, $sortorder);

foreach ($currencies as $code => $name) {
	$rate_display = isset($rates[$code]) ? price2num($rates[$code], 4) : '?';
	print '<td class="liste_titre center nowraponall" colspan="3">'.$form->textwithpicto('<strong>'.$code.'</strong>', $langs->trans('CurrencyRateHelp', $code, $rate_display)).'</td>';
}

print '<td class="liste_titre"></td>';
print '</tr>';

// --- Sub-header for currency columns ---
print '<tr class="liste_titre">';
print '<td></td><td></td><td></td>';
foreach ($currencies as $code => $name) {
	print '<td class="liste_titre right nowraponall">'.$form->textwithpicto($langs->trans('Fixed'), $langs->trans('FixedPriceHTHelp')).'</td>';
	print '<td class="liste_titre right nowraponall">'.$form->textwithpicto($langs->trans('Auto'), $langs->trans('AutoConvertedPriceHelp')).'</td>';
	print '<td class="liste_titre center">'.$form->textwithpicto('%', $langs->trans('DivergenceHelp', getDolGlobalString('FIXEDPRICE_DIVERGENCE_THRESHOLD', '10'))).'</td>';
}
print '<td></td>';
print '</tr>';

// --- Filter row ---
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" name="search_ref" value="'.dol_escape_htmltag($search_ref).'" class="maxwidth100" placeholder="'.$langs->trans('Search').'"></td>';
print '<td class="liste_titre"><input type="text" name="search_label" value="'.dol_escape_htmltag($search_label).'" class="maxwidth150" placeholder="'.$langs->trans('Search').'"></td>';
print '<td class="liste_titre"></td>';
foreach ($currencies as $code => $name) {
	print '<td class="liste_titre"></td>';
	print '<td class="liste_titre"></td>';
	print '<td class="liste_titre"></td>';
}
print '<td class="liste_titre center maxwidthsearch">';
print $form->showFilterButtons();
print '</td>';
print '</tr>';

// Handle search clear
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_ref = '';
	$search_label = '';
}

// --- Data rows ---
$fpobj = new FixedPrice($db);

$i = 0;
while ($i < $num) {
	$obj = $db->fetch_object($resql);
	$product_id = (int) $obj->product_id;
	$base_price = (float) $obj->price;

	// Fetch all fixed prices for this product
	$existing = $fpobj->fetchByProduct($product_id);

	$is_editing = ($action == 'editfixedprice' && $productid == $product_id);

	print '<tr class="oddeven">';

	if ($is_editing) {
		// --- EDIT MODE ---
		print '<input type="hidden" name="action" value="updatefixedprice">';
		print '<input type="hidden" name="productid" value="'.$product_id.'">';

		// Product ref (linked)
		print '<td><a href="'.DOL_URL_ROOT.'/product/price.php?id='.$product_id.'">'.dol_escape_htmltag($obj->ref).'</a></td>';
		// Label
		print '<td>'.dol_escape_htmltag($obj->label).'</td>';
		// Base price
		print '<td class="right nowraponall">'.price($base_price, 0, $langs, 1, -1, -1, $conf->currency).'</td>';

		foreach ($currencies as $code => $name) {
			$fp = isset($existing[$code]) ? $existing[$code] : null;
			$auto_price = $base_price * $rates[$code];
			$current_val = $fp ? price2num($fp->fixed_price_ht, 'MU') : '';

			// Fixed price input
			print '<td class="right"><input type="text" name="fixedprice_ht_'.$code.'" value="'.$current_val.'" size="10" class="flat right" placeholder="—"></td>';

			// Auto price (read-only)
			print '<td class="right nowraponall opacitymedium">'.price($auto_price, 0, $langs, 1, -1, -1, $code).'</td>';

			// Divergence
			if ($fp && $fp->fixed_price_ht > 0) {
				$divergence = ($auto_price > 0) ? abs(($fp->fixed_price_ht - $auto_price) / $auto_price) * 100 : 0;
				$divclass = ($divergence > $threshold_default) ? 'fixedprice-divergence-red' : (($divergence > $threshold_default * 0.8) ? 'fixedprice-divergence-amber' : 'fixedprice-divergence-green');
				print '<td class="center"><span class="'.$divclass.'">'.price2num($divergence, 1).'%</span></td>';
			} else {
				print '<td class="center opacitymedium">—</td>';
			}

			// Hidden enabled state (preserve current)
			if ($fp) {
				print '<input type="hidden" name="fixedprice_enabled_'.$code.'" value="'.$fp->enabled.'">';
			}
		}

		// Save / Cancel
		print '<td class="center nowraponall">';
		print '<input type="submit" class="button button-save smallpaddingimp" value="'.$langs->trans('Save').'">';
		print ' <a class="button button-cancel smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?'.$param.'">'.$langs->trans('Cancel').'</a>';
		print '</td>';
	} else {
		// --- VIEW MODE ---

		// Product ref (linked)
		print '<td><a href="'.DOL_URL_ROOT.'/product/price.php?id='.$product_id.'">'.dol_escape_htmltag($obj->ref).'</a></td>';
		// Label
		print '<td>'.dol_escape_htmltag($obj->label).'</td>';
		// Base price
		print '<td class="right nowraponall">'.price($base_price, 0, $langs, 1, -1, -1, $conf->currency).'</td>';

		foreach ($currencies as $code => $name) {
			$fp = isset($existing[$code]) ? $existing[$code] : null;
			$auto_price = $base_price * $rates[$code];

			// Fixed price + toggle inline
			if ($fp) {
				$display_price = price($fp->fixed_price_ht, 0, $langs, 1, -1, -1, $code);
				print '<td class="right nowraponall">';
				if ($permwrite) {
					$toggle_title = dol_escape_htmltag($fp->enabled ? $langs->trans('ClickToDisable', $code) : $langs->trans('ClickToEnable', $code));
					print '<a href="'.$_SERVER['PHP_SELF'].'?action=togglefixedprice&token='.newToken().'&lineid='.$fp->id.$param.'" title="'.$toggle_title.'" style="display:inline-block;margin-right:10px">';
					print img_picto('', $fp->enabled ? 'switch_on' : 'switch_off', 'class="valignmiddle" style="width:30px"');
					print '</a>';
				}
				if (!$fp->enabled) {
					print '<span class="opacitymedium">'.$display_price.'</span>';
				} else {
					print $display_price;
				}
				print '</td>';
			} else {
				print '<td class="right opacitymedium">—</td>';
			}

			// Auto price
			print '<td class="right nowraponall opacitymedium" title="'.dol_escape_htmltag(price2num($base_price, 'MU').' x '.$rates[$code]).'">'.price($auto_price, 0, $langs, 1, -1, -1, $code).'</td>';

			// Divergence
			if ($fp && $fp->fixed_price_ht > 0) {
				$divergence = ($auto_price > 0) ? abs(($fp->fixed_price_ht - $auto_price) / $auto_price) * 100 : 0;
				$divclass = ($divergence > $threshold_default) ? 'fixedprice-divergence-red' : (($divergence > $threshold_default * 0.8) ? 'fixedprice-divergence-amber' : 'fixedprice-divergence-green');
				$div_title = dol_escape_htmltag($langs->trans('DivergenceTooltip', price2num($divergence, 1), price2num($threshold_default, 1)));
				print '<td class="center" title="'.$div_title.'"><span class="'.$divclass.'">'.price2num($divergence, 1).'%</span></td>';
			} else {
				print '<td class="center opacitymedium">—</td>';
			}
		}

		// Actions — just edit pencil
		print '<td class="center nowraponall">';
		if ($permwrite) {
			print '<a class="editfielda" href="'.$_SERVER['PHP_SELF'].'?action=editfixedprice&token='.newToken().'&productid='.$product_id.$param.'" title="'.dol_escape_htmltag($langs->trans('EditFixedPricesForProduct')).'">'.img_picto($langs->trans('Edit'), 'edit').'</a>';
		}
		print '</td>';
	}

	print '</tr>';
	$i++;
}

if ($num == 0) {
	$colspan = 3 + (count($currencies) * 3) + 1;
	print '<tr class="oddeven"><td colspan="'.$colspan.'" class="opacitymedium center">'.$langs->trans("NoRecordFound").'</td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
