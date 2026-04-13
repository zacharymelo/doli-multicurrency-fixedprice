<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/boxes/box_fixedprice_divergence.php
 * \ingroup fixedprice
 * \brief   Dashboard widget showing products with fixed prices that have
 *          diverged beyond their threshold from the auto-converted price
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Class box_fixedprice_divergence
 *
 * Shows products whose fixed multicurrency prices have diverged
 * beyond their configured threshold, sorted by worst divergence first.
 */
class box_fixedprice_divergence extends ModeleBoxes
{
	/** @var string Box code identifier */
	public $boxcode = "fixedpricedivergence";

	/** @var string Box image/icon */
	public $boximg = "object_multicurrency";

	/** @var string Box label translation key */
	public $boxlabel = "BoxFixedpriceDivergence";

	/** @var array Required modules */
	public $depends = array("fixedprice");

	/**
	 * Constructor
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $param Extra parameters
	 */
	public function __construct($db, $param = '')
	{
		global $user, $langs;
		$this->db = $db;

		$this->hidden = !($user->hasRight('fixedprice', 'read'));

		$langs->load('fixedprice@fixedprice');
	}

	/**
	 * Load data for the box.
	 *
	 * Queries all enabled fixed prices, computes divergence against current
	 * exchange rates, and shows products exceeding their threshold.
	 *
	 * @param  int $max Maximum number of records to show
	 * @return void
	 */
	public function loadBox($max = 10)
	{
		global $conf, $langs;

		$this->max = $max;

		$langs->load('fixedprice@fixedprice');

		$this->info_box_head = array(
			'text' => $langs->trans("BoxFixedpriceDivergence", $max),
		);

		dol_include_once('/fixedprice/lib/fixedprice.lib.php');
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';

		// Fetch all enabled fixed prices
		$sql = "SELECT pfp.rowid, pfp.fk_product, pfp.multicurrency_code,";
		$sql .= " pfp.fixed_price_ht, pfp.divergence_threshold,";
		$sql .= " p.ref, p.label, p.price as base_price";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_fixed_price pfp";
		$sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = pfp.fk_product";
		$sql .= " WHERE pfp.enabled = 1";
		$sql .= " AND pfp.entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->info_box_contents[0][0] = array(
				'td' => '',
				'text' => $this->db->lasterror(),
			);
			return;
		}

		// Build array of divergences
		$alerts = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$listrate = MultiCurrency::getIdAndTxFromCode($this->db, $obj->multicurrency_code);
			if (empty($listrate[0])) {
				continue;
			}
			$rate = (float) $listrate[1];
			$auto_price = (float) $obj->base_price * $rate;

			$divergence = fixedpriceCalcDivergence($obj->fixed_price_ht, $auto_price);
			$threshold = fixedpriceResolveThreshold($this->db, $obj->fk_product, $obj->multicurrency_code);

			if ($divergence > $threshold) {
				$alerts[] = array(
					'fk_product' => $obj->fk_product,
					'ref' => $obj->ref,
					'label' => $obj->label,
					'currency' => $obj->multicurrency_code,
					'fixed_price' => $obj->fixed_price_ht,
					'auto_price' => $auto_price,
					'divergence' => $divergence,
					'threshold' => $threshold,
				);
			}
		}

		// Sort by divergence descending
		usort($alerts, function ($a, $b) {
			return $b['divergence'] <=> $a['divergence'];
		});

		if (empty($alerts)) {
			$this->info_box_contents[0][0] = array(
				'td' => 'class="center opacitymedium"',
				'text' => $langs->trans("NoDivergenceAlerts"),
			);
			return;
		}

		$productstatic = new Product($this->db);
		$line = 0;

		foreach ($alerts as $alert) {
			if ($line >= $max) {
				break;
			}

			$productstatic->id = $alert['fk_product'];
			$productstatic->ref = $alert['ref'];
			$productstatic->label = $alert['label'];

			// Product link
			$this->info_box_contents[$line][] = array(
				'td' => 'class="tdoverflowmax150"',
				'text' => $productstatic->getNomUrl(1),
				'asis' => 1,
			);

			// Currency
			$this->info_box_contents[$line][] = array(
				'td' => 'class="center"',
				'text' => $alert['currency'],
			);

			// Fixed price
			$this->info_box_contents[$line][] = array(
				'td' => 'class="right nowraponall"',
				'text' => price($alert['fixed_price'], 0, $langs, 1, -1, -1, $alert['currency']),
			);

			// Auto price
			$this->info_box_contents[$line][] = array(
				'td' => 'class="right nowraponall"',
				'text' => price($alert['auto_price'], 0, $langs, 1, -1, -1, $alert['currency']),
			);

			// Divergence %
			$divclass = $alert['divergence'] > ($alert['threshold'] * 1.5) ? 'fixedprice-divergence-red' : 'fixedprice-divergence-amber';
			$this->info_box_contents[$line][] = array(
				'td' => 'class="center nowraponall"',
				'text' => '<span class="'.$divclass.'">'.price2num($alert['divergence'], 1).'%</span>',
				'asis' => 1,
			);

			// Threshold
			$this->info_box_contents[$line][] = array(
				'td' => 'class="center"',
				'text' => price2num($alert['threshold'], 1).'%',
			);

			$line++;
		}
	}

	/**
	 * Show the box
	 *
	 * @param  array  $head     Array with properties of box title
	 * @param  array  $contents Array with properties of box lines
	 * @param  int    $nooutput 1 = return string instead of printing
	 * @return string           HTML if $nooutput is 1
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
