<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_fixedprice.class.php
 * \ingroup fixedprice
 * \brief   Hook actions for the Fixed Multicurrency Price module
 */

/**
 * Class ActionsFixedprice
 *
 * Implements hooks for:
 * - productpricecard: UI for managing fixed prices on the product price page
 * - invoicecard/ordercard/propalcard: intercept addline to inject fixed prices
 */
class ActionsFixedprice
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var array Result set returned to hook manager */
	public $results = array();

	/** @var string HTML output injected by hook into the page */
	public $resprints = '';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook into doActions for multiple contexts.
	 *
	 * - productpricecard: save/toggle/delete fixed prices
	 * - invoicecard/ordercard/propalcard: inject fixed price into $_POST before addline
	 *
	 * @param  array       $parameters Hook parameters
	 * @param  object      $object     Current page object
	 * @param  string      $action     Current action
	 * @param  HookManager $hookmanager Hook manager instance
	 * @return int                     0 = continue other hooks
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs, $db;

		if (!isModEnabled('fixedprice')) {
			return 0;
		}

		$currentcontext = explode(':', $parameters['context']);

		// --- Product price page: save/toggle/delete ---
		if (in_array('productpricecard', $currentcontext)) {
			$this->_doActionsProductPrice($object, $action, $user);
		}

		// --- Invoice/Order/Proposal: intercept addline ---
		if ($action == 'addline'
			&& (in_array('invoicecard', $currentcontext)
				|| in_array('ordercard', $currentcontext)
				|| in_array('propalcard', $currentcontext))
		) {
			$is_ordercard = in_array('ordercard', $currentcontext);
			$this->_doActionsAddline($object, $action, $is_ordercard);
		}

		return 0;
	}

	/**
	 * Handle save/toggle/delete actions on the product price page.
	 *
	 * @param  object $object Product object
	 * @param  string $action Current action
	 * @param  User   $user   Current user
	 * @return void
	 */
	private function _doActionsProductPrice(&$object, &$action, $user)
	{
		global $langs;

		dol_include_once('/fixedprice/class/fixedprice.class.php');
		$langs->load('fixedprice@fixedprice');

		if ($action == 'savefixedprice' && ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
			$currency_code = GETPOST('fixedprice_currency', 'alpha');
			$price_ht = price2num(GETPOST('fixedprice_ht', 'alpha'));
			$threshold = GETPOST('fixedprice_threshold', 'alpha');

			if (empty($currency_code) || ($price_ht === '' || $price_ht === null)) {
				setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("FixedPriceHT")), null, 'errors');
				$action = '';
				return;
			}

			$fp = new FixedPrice($this->db);
			$exists = $fp->fetchByProductCurrency($object->id, $currency_code);

			$fp->fk_product = $object->id;
			$fp->multicurrency_code = $currency_code;
			$fp->fixed_price_ht = (float) $price_ht;
			$fp->price_base_type = 'HT';
			$fp->divergence_threshold = ($threshold !== '' && $threshold !== null) ? (float) $threshold : null;

			if ($exists > 0) {
				$result = $fp->update($user);
			} else {
				$fp->enabled = 1;
				$result = $fp->create($user);
			}

			if ($result > 0) {
				setEventMessages($langs->trans("FixedPriceSaved"), null, 'mesgs');
			} else {
				setEventMessages($fp->error, null, 'errors');
			}
			$action = '';
		}

		if ($action == 'togglefixedprice' && ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
			$lineid = GETPOSTINT('lineid');
			$fp = new FixedPrice($this->db);
			if ($fp->fetch($lineid) > 0) {
				$result = $fp->toggleEnabled($user);
				if ($result > 0) {
					setEventMessages($langs->trans("FixedPriceToggled"), null, 'mesgs');
				} else {
					setEventMessages($fp->error, null, 'errors');
				}
			}
			$action = '';
		}

		if ($action == 'deletefixedprice' && ($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
			$lineid = GETPOSTINT('lineid');
			$fp = new FixedPrice($this->db);
			if ($fp->fetch($lineid) > 0) {
				$result = $fp->delete($user);
				if ($result > 0) {
					setEventMessages($langs->trans("FixedPriceDeleted"), null, 'mesgs');
				} else {
					setEventMessages($fp->error, null, 'errors');
				}
			}
			$action = '';
		}
	}

	/**
	 * Intercept addline action to inject fixed multicurrency price into $_POST.
	 *
	 * Fires BEFORE the standard addline processing on invoice/order/proposal cards.
	 * If the product has an enabled fixed price for the document's multicurrency,
	 * and the user hasn't manually entered a multicurrency price, we inject it.
	 *
	 * @param  object $object Document object (Facture, Commande, Propal)
	 * @param  string $action Current action
	 * @return void
	 */
	private function _doActionsAddline(&$object, &$action, $is_ordercard = false)
	{
		global $conf;

		if (!is_object($object) || empty($object->multicurrency_code)) {
			return;
		}
		if ($object->multicurrency_code == $conf->currency) {
			return;
		}
		$idprod = GETPOSTINT('idprod');
		if (empty($idprod) || $idprod <= 0) {
			return;
		}

		// Query for a fixed price
		$sql = "SELECT fixed_price_ht FROM ".MAIN_DB_PREFIX."product_fixed_price";
		$sql .= " WHERE fk_product = ".((int) $idprod);
		$sql .= " AND multicurrency_code = '".$this->db->escape($object->multicurrency_code)."'";
		$sql .= " AND enabled = 1";
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) == 0) {
			return;
		}

		$obj = $this->db->fetch_object($resql);
		$fixed_price = (float) $obj->fixed_price_ht;

		if ($is_ordercard) {
			// Order card does NOT have a $price_ht_devise branch in its price
			// priority logic for products — back-calculate the base currency price
			// from the fixed multicurrency price using the document's exchange rate
			$rate = (float) $object->multicurrency_tx;
			if (empty($rate) || $rate == 0) {
				$rate = 1;
			}
			$base_price = $fixed_price / $rate;
			$_POST['price_ht'] = (string) price2num($base_price, 'MU');
		} else {
			// Invoice and proposal cards have a $price_ht_devise branch that
			// picks up multicurrency_price_ht directly
			$_POST['multicurrency_price_ht'] = (string) $fixed_price;
			$_POST['price_ht'] = '';
		}

		dol_syslog('fixedprice: injected fixed price '.$fixed_price.' '.$object->multicurrency_code.' for product '.$idprod.($is_ordercard ? ' (order: base_ht='.$_POST['price_ht'].')' : ''), LOG_INFO);

		// Divergence warning — max 4 %s params (Dolibarr trans() sprintf limit)
		if (getDolGlobalString('FIXEDPRICE_WARN_ON_APPLY')) {
			$langs->load('fixedprice@fixedprice');

			$sql2 = "SELECT p.ref, p.price FROM ".MAIN_DB_PREFIX."product p WHERE p.rowid = ".((int) $idprod);
			$resql2 = $this->db->query($sql2);
			if ($resql2 && $this->db->num_rows($resql2) > 0) {
				$prodobj = $this->db->fetch_object($resql2);

				$sql3 = "SELECT rate FROM ".MAIN_DB_PREFIX."multicurrency_rate mcr";
				$sql3 .= " JOIN ".MAIN_DB_PREFIX."multicurrency mc ON mc.rowid = mcr.fk_multicurrency";
				$sql3 .= " WHERE mc.code = '".$this->db->escape($object->multicurrency_code)."'";
				$sql3 .= " AND mc.entity = ".((int) $conf->entity);
				$sql3 .= " ORDER BY mcr.date_sync DESC LIMIT 1";
				$resql3 = $this->db->query($sql3);
				$rate = 1;
				if ($resql3 && $this->db->num_rows($resql3) > 0) {
					$rateobj = $this->db->fetch_object($resql3);
					$rate = (float) $rateobj->rate;
				}

				$auto_price = (float) $prodobj->price * $rate;
				$divergence = ($auto_price > 0) ? abs(($fixed_price - $auto_price) / $auto_price) * 100 : 0;
				$threshold = (float) getDolGlobalString('FIXEDPRICE_DIVERGENCE_THRESHOLD', '10');

				$msg = $langs->trans(
					"FixedPriceApplied",
					$prodobj->ref,
					$object->multicurrency_code,
					price($fixed_price, 0, $langs, 1, -1, -1, $object->multicurrency_code),
					price2num($divergence, 1)
				);

				if ($divergence > $threshold) {
					setEventMessages($msg, null, 'warnings');
				} else {
					setEventMessages($msg, null, 'mesgs');
				}
			}
		}
	}

	/**
	 * Inject the Fixed Multicurrency Prices section on the product price page.
	 *
	 * Fires on the addMoreActionsButtons hook in the productpricecard context.
	 * Closes the tabsAction div, outputs our section, then reopens tabsAction
	 * so the default buttons still render correctly.
	 *
	 * @param  array       $parameters Hook parameters
	 * @param  object      $object     Product object
	 * @param  string      $action     Current action
	 * @param  HookManager $hookmanager Hook manager instance
	 * @return int                     0 = continue other hooks
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs, $form;

		if (!isModEnabled('fixedprice')) {
			return 0;
		}

		$currentcontext = explode(':', $parameters['context']);
		if (!in_array('productpricecard', $currentcontext)) {
			return 0;
		}

		// View access follows product permissions — if you can see the price page, you can see fixed prices
		if (!$user->hasRight('produit', 'lire') && !$user->hasRight('service', 'lire')) {
			return 0;
		}

		$langs->load('fixedprice@fixedprice');
		dol_include_once('/fixedprice/class/fixedprice.class.php');
		dol_include_once('/fixedprice/lib/fixedprice.lib.php');

		// Print directly — addMoreActionsButtons callers don't output $hookmanager->resPrint
		// Close tabsAction div, output our section, reopen tabsAction for default buttons
		print '</div>'; // close tabsAction

		print $this->_renderFixedPricesSection($object);

		print '<div class="tabsAction">'; // reopen for default buttons

		return 0;
	}

	/**
	 * Render the Fixed Multicurrency Prices section HTML.
	 *
	 * @param  Product $object Product object
	 * @return string          HTML output
	 */
	private function _renderFixedPricesSection($object)
	{
		global $conf, $user, $langs, $form;

		$out = '';
		$out .= "\n".'<!-- Fixed Multicurrency Prices section -->'."\n";
		$out .= '<div class="fichecenter">'."\n";

		$out .= '<style>';
		$out .= '.fixedprice-divergence-green { color: #28a745; font-weight: bold; }';
		$out .= '.fixedprice-divergence-amber { color: #ffc107; font-weight: bold; }';
		$out .= '.fixedprice-divergence-red { color: #dc3545; font-weight: bold; }';
		$out .= '</style>';

		$out .= load_fiche_titre($langs->trans("FixedMulticurrencyPrices"), '', '');

		// Fetch existing fixed prices for this product
		$fpobj = new FixedPrice($this->db);
		$existing = $fpobj->fetchByProduct($object->id);

		// Get all active multicurrencies (excluding base)
		$currencies = $this->_getActiveCurrencies();

		if (empty($currencies)) {
			$out .= '<div class="opacitymedium">'.$langs->trans("NoOtherCurrencyDefined").'</div>';
			$out .= '</div></div>';
			return $out;
		}

		$out .= '<div class="div-table-responsive-no-min">';
		$out .= '<table class="noborder centpercent">';

		// Header
		$out .= '<tr class="liste_titre">';
		$out .= '<td>'.$langs->trans("Currency").'</td>';
		$out .= '<td class="right">'.$langs->trans("FixedPriceHT").'</td>';
		$out .= '<td class="right">'.$langs->trans("AutoConvertedPrice").'</td>';
		$out .= '<td class="center">'.$langs->trans("Divergence").'</td>';
		$out .= '<td class="center">'.$langs->trans("DivergenceThreshold").'</td>';
		$out .= '<td class="center">'.$langs->trans("Enabled").'</td>';
		$out .= '<td class="right">'.$langs->trans("Action").'</td>';
		$out .= '</tr>';

		// Existing fixed prices
		foreach ($currencies as $code => $label) {
			$fp = isset($existing[$code]) ? $existing[$code] : null;
			$auto_price = fixedpriceGetAutoPrice($this->db, $object->id, $code);
			$threshold = fixedpriceResolveThreshold($this->db, $object->id, $code);

			if ($fp) {
				$divergence = fixedpriceCalcDivergence($fp->fixed_price_ht, $auto_price);
				$divclass = fixedpriceDivergenceClass($divergence, $threshold);

				// Determine threshold source label
				$threshold_label = '';
				if ($fp->divergence_threshold !== null && $fp->divergence_threshold !== '') {
					$threshold_label = price2num($fp->divergence_threshold, 1).'%';
				} else {
					// Check if inherited from parent
					$sql = "SELECT pfp.divergence_threshold";
					$sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination pac";
					$sql .= " JOIN ".MAIN_DB_PREFIX."product_fixed_price pfp ON pfp.fk_product = pac.fk_product_parent";
					$sql .= " WHERE pac.fk_product_child = ".((int) $object->id);
					$sql .= " AND pfp.multicurrency_code = '".$this->db->escape($code)."'";
					$sql .= " AND pfp.entity = ".((int) $conf->entity);
					$resql = $this->db->query($sql);
					if ($resql && $this->db->num_rows($resql) > 0) {
						$pobj = $this->db->fetch_object($resql);
						if ($pobj->divergence_threshold !== null && $pobj->divergence_threshold !== '') {
							$threshold_label = price2num($threshold, 1).'% <span class="opacitymedium">('.$langs->trans("InheritedFromParent").')</span>';
						} else {
							$threshold_label = price2num($threshold, 1).'% <span class="opacitymedium">('.$langs->trans("InheritedFromGlobal").')</span>';
						}
					} else {
						$threshold_label = price2num($threshold, 1).'% <span class="opacitymedium">('.$langs->trans("InheritedFromGlobal").')</span>';
					}
				}

				$out .= '<tr class="oddeven">';
				$out .= '<td><strong>'.$code.'</strong> ('.$label.')</td>';

				// Fixed price with inline edit form
				$out .= '<td class="right">';
				$out .= '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" style="display:inline">';
				$out .= '<input type="hidden" name="token" value="'.newToken().'">';
				$out .= '<input type="hidden" name="action" value="savefixedprice">';
				$out .= '<input type="hidden" name="fixedprice_currency" value="'.$code.'">';
				$out .= '<input type="text" name="fixedprice_ht" value="'.price2num($fp->fixed_price_ht, 'MU').'" size="10" class="right">';
				$out .= ' <input type="text" name="fixedprice_threshold" value="'.($fp->divergence_threshold !== null && $fp->divergence_threshold !== '' ? price2num($fp->divergence_threshold, 1) : '').'" size="5" class="right" placeholder="%">';
				$out .= ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans("Save").'">';
				$out .= '</form>';
				$out .= '</td>';

				// Auto-converted price
				$out .= '<td class="right">'.price($auto_price, 0, $langs, 1, -1, -1, $code).'</td>';

				// Divergence
				$out .= '<td class="center"><span class="'.$divclass.'">'.price2num($divergence, 1).'%</span></td>';

				// Threshold
				$out .= '<td class="center">'.$threshold_label.'</td>';

				// Enabled toggle
				$out .= '<td class="center">';
				$out .= '<a href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=togglefixedprice&token='.newToken().'&lineid='.$fp->id.'">';
				if ($fp->enabled) {
					$out .= img_picto($langs->trans("Enabled"), 'switch_on');
				} else {
					$out .= img_picto($langs->trans("Disabled"), 'switch_off');
				}
				$out .= '</a>';
				$out .= '</td>';

				// Delete
				$out .= '<td class="right">';
				if (($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
					$out .= '<a href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=deletefixedprice&token='.newToken().'&lineid='.$fp->id.'">';
					$out .= img_delete();
					$out .= '</a>';
				}
				$out .= '</td>';

				$out .= '</tr>';
			} else {
				// No fixed price yet — show add form
				$out .= '<tr class="oddeven">';
				$out .= '<td><strong>'.$code.'</strong> ('.$label.')</td>';

				$out .= '<td class="right">';
				if (($user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer'))) {
					$out .= '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" style="display:inline">';
					$out .= '<input type="hidden" name="token" value="'.newToken().'">';
					$out .= '<input type="hidden" name="action" value="savefixedprice">';
					$out .= '<input type="hidden" name="fixedprice_currency" value="'.$code.'">';
					$out .= '<input type="text" name="fixedprice_ht" value="" size="10" class="right" placeholder="'.$langs->trans("FixedPriceHT").'">';
					$out .= ' <input type="text" name="fixedprice_threshold" value="" size="5" class="right" placeholder="%">';
					$out .= ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans("Add").'">';
					$out .= '</form>';
				}
				$out .= '</td>';

				$out .= '<td class="right">'.price($auto_price, 0, $langs, 1, -1, -1, $code).'</td>';
				$out .= '<td class="center">—</td>';
				$out .= '<td class="center">'.price2num(fixedpriceResolveThreshold($this->db, $object->id, $code), 1).'%</td>';
				$out .= '<td class="center">—</td>';
				$out .= '<td class="right"></td>';
				$out .= '</tr>';
			}
		}

		$out .= '</table>';
		$out .= '</div>'; // div-table-responsive

		$out .= '</div>'; // fichecenter
		$out .= '<div class="clearboth"></div>';

		return $out;
	}

	/**
	 * Get all active multicurrencies excluding the base currency.
	 *
	 * @return array Associative array code => name
	 */
	private function _getActiveCurrencies()
	{
		global $conf;

		$list = array();

		$sql = "SELECT code, name FROM ".MAIN_DB_PREFIX."multicurrency";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND code != '".$this->db->escape($conf->currency)."'";
		$sql .= " ORDER BY code ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[$obj->code] = $obj->name;
			}
		}

		return $list;
	}
}
