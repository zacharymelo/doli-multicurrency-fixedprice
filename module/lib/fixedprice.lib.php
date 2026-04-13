<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/fixedprice.lib.php
 * \ingroup fixedprice
 * \brief   Shared helper functions for the Fixed Multicurrency Price module
 */

/**
 * Resolve the effective divergence threshold for a product+currency.
 *
 * Inheritance chain:
 * 1. Per-product override on the llx_product_fixed_price row
 * 2. Parent product override (via llx_product_attribute_combination)
 * 3. Global default (FIXEDPRICE_DIVERGENCE_THRESHOLD constant)
 *
 * @param  DoliDB  $db                Database handler
 * @param  int     $fk_product        Product ID
 * @param  string  $multicurrency_code Currency code
 * @return float                      Threshold percentage
 */
function fixedpriceResolveThreshold($db, $fk_product, $multicurrency_code)
{
	global $conf;

	// 1. Check per-product override
	$sql = "SELECT divergence_threshold FROM ".MAIN_DB_PREFIX."product_fixed_price";
	$sql .= " WHERE fk_product = ".((int) $fk_product);
	$sql .= " AND multicurrency_code = '".$db->escape($multicurrency_code)."'";
	$sql .= " AND entity = ".((int) $conf->entity);
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		if ($obj->divergence_threshold !== null && $obj->divergence_threshold !== '') {
			return (float) $obj->divergence_threshold;
		}
	}

	// 2. Check parent product override (variant inheritance)
	$sql = "SELECT pfp.divergence_threshold";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination pac";
	$sql .= " JOIN ".MAIN_DB_PREFIX."product_fixed_price pfp ON pfp.fk_product = pac.fk_product_parent";
	$sql .= " WHERE pac.fk_product_child = ".((int) $fk_product);
	$sql .= " AND pfp.multicurrency_code = '".$db->escape($multicurrency_code)."'";
	$sql .= " AND pfp.entity = ".((int) $conf->entity);
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		if ($obj->divergence_threshold !== null && $obj->divergence_threshold !== '') {
			return (float) $obj->divergence_threshold;
		}
	}

	// 3. Global default
	return (float) getDolGlobalString('FIXEDPRICE_DIVERGENCE_THRESHOLD', '10');
}

/**
 * Calculate the divergence percentage between a fixed price and auto-converted price.
 *
 * @param  float  $fixed_price     The fixed price
 * @param  float  $auto_price      The auto-converted price (base price × rate)
 * @return float                   Absolute divergence percentage (0 if auto_price is 0)
 */
function fixedpriceCalcDivergence($fixed_price, $auto_price)
{
	if (empty($auto_price) || $auto_price == 0) {
		return 0;
	}
	return abs(($fixed_price - $auto_price) / $auto_price) * 100;
}

/**
 * Get the auto-converted price for a product in a given currency.
 *
 * Fetches the product's base selling price and multiplies by the current
 * exchange rate for the target currency.
 *
 * @param  DoliDB  $db                Database handler
 * @param  int     $fk_product        Product ID
 * @param  string  $multicurrency_code Target currency code
 * @return float                      Auto-converted price HT (0 if unavailable)
 */
function fixedpriceGetAutoPrice($db, $fk_product, $multicurrency_code)
{
	global $conf;

	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';

	$product = new Product($db);
	if ($product->fetch($fk_product) <= 0) {
		return 0;
	}

	$listrate = MultiCurrency::getIdAndTxFromCode($db, $multicurrency_code);
	if (empty($listrate[0])) {
		return 0;
	}

	$rate = (float) $listrate[1];
	return (float) $product->price * $rate;
}

/**
 * Get the CSS class for a divergence value relative to a threshold.
 *
 * @param  float  $divergence  Divergence percentage
 * @param  float  $threshold   Threshold percentage
 * @return string              CSS class name
 */
function fixedpriceDivergenceClass($divergence, $threshold)
{
	if ($divergence > $threshold) {
		return 'fixedprice-divergence-red';
	} elseif ($divergence > ($threshold * 0.8)) {
		return 'fixedprice-divergence-amber';
	}
	return 'fixedprice-divergence-green';
}

/**
 * Fetch the fixed price for a product+currency if enabled.
 *
 * @param  DoliDB  $db                 Database handler
 * @param  int     $fk_product         Product ID
 * @param  string  $multicurrency_code  Currency code
 * @return array|null                   Array with fixed_price_ht, fixed_price_ttc, price_base_type, or null if none/disabled
 */
function fixedpriceFetchEnabled($db, $fk_product, $multicurrency_code)
{
	global $conf;

	$sql = "SELECT fixed_price_ht, fixed_price_ttc, price_base_type";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_fixed_price";
	$sql .= " WHERE fk_product = ".((int) $fk_product);
	$sql .= " AND multicurrency_code = '".$db->escape($multicurrency_code)."'";
	$sql .= " AND enabled = 1";
	$sql .= " AND entity = ".((int) $conf->entity);

	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		return array(
			'fixed_price_ht' => $obj->fixed_price_ht,
			'fixed_price_ttc' => $obj->fixed_price_ttc,
			'price_base_type' => $obj->price_base_type,
		);
	}
	return null;
}
