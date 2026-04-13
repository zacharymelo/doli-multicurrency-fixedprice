<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/fixedprice.class.php
 * \ingroup fixedprice
 * \brief   CRUD class for the llx_product_fixed_price table
 */

/**
 * Class FixedPrice
 *
 * Manages per-product, per-currency fixed selling prices that override
 * the automatic exchange-rate conversion.
 */
class FixedPrice
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var int Row ID */
	public $id;

	/** @var int Product ID */
	public $fk_product;

	/** @var string Currency code (e.g. 'CAD') */
	public $multicurrency_code;

	/** @var float Fixed price excl. tax */
	public $fixed_price_ht;

	/** @var float Fixed price incl. tax */
	public $fixed_price_ttc;

	/** @var string Price base type ('HT' or 'TTC') */
	public $price_base_type = 'HT';

	/** @var int Enabled flag (1=active, 0=paused) */
	public $enabled = 1;

	/** @var float|null Per-product divergence threshold override */
	public $divergence_threshold;

	/** @var string Date of creation */
	public $date_creation;

	/** @var int Author user ID */
	public $fk_user_author;

	/** @var int Last modifier user ID */
	public $fk_user_modif;

	/** @var int Entity */
	public $entity;

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
	 * Fetch a single fixed price by ID.
	 *
	 * @param  int $id Row ID
	 * @return int     1 if found, 0 if not found, <0 on error
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, fk_product, multicurrency_code, fixed_price_ht, fixed_price_ttc,";
		$sql .= " price_base_type, enabled, divergence_threshold, date_creation,";
		$sql .= " fk_user_author, fk_user_modif, entity";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_fixed_price";
		$sql .= " WHERE rowid = ".((int) $id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->num_rows($resql) == 0) {
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->_setFromObj($obj);
		return 1;
	}

	/**
	 * Fetch a fixed price by product + currency.
	 *
	 * @param  int    $fk_product        Product ID
	 * @param  string $multicurrency_code Currency code
	 * @return int                        1 if found, 0 if not found, <0 on error
	 */
	public function fetchByProductCurrency($fk_product, $multicurrency_code)
	{
		global $conf;

		$sql = "SELECT rowid, fk_product, multicurrency_code, fixed_price_ht, fixed_price_ttc,";
		$sql .= " price_base_type, enabled, divergence_threshold, date_creation,";
		$sql .= " fk_user_author, fk_user_modif, entity";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_fixed_price";
		$sql .= " WHERE fk_product = ".((int) $fk_product);
		$sql .= " AND multicurrency_code = '".$this->db->escape($multicurrency_code)."'";
		$sql .= " AND entity = ".((int) $conf->entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->num_rows($resql) == 0) {
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->_setFromObj($obj);
		return 1;
	}

	/**
	 * Fetch all fixed prices for a product.
	 *
	 * @param  int   $fk_product Product ID
	 * @return array             Array of FixedPrice objects (empty on error or no results)
	 */
	public function fetchByProduct($fk_product)
	{
		global $conf;

		$list = array();

		$sql = "SELECT rowid, fk_product, multicurrency_code, fixed_price_ht, fixed_price_ttc,";
		$sql .= " price_base_type, enabled, divergence_threshold, date_creation,";
		$sql .= " fk_user_author, fk_user_modif, entity";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_fixed_price";
		$sql .= " WHERE fk_product = ".((int) $fk_product);
		$sql .= " AND entity = ".((int) $conf->entity);
		$sql .= " ORDER BY multicurrency_code ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $list;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$fp = new FixedPrice($this->db);
			$fp->_setFromObj($obj);
			$list[$obj->multicurrency_code] = $fp;
		}

		return $list;
	}

	/**
	 * Create a new fixed price record.
	 *
	 * @param  User $user User performing the action
	 * @return int        >0 on success (new ID), <0 on error
	 */
	public function create($user)
	{
		global $conf;

		$this->entity = $conf->entity;
		$this->date_creation = dol_now();
		$this->fk_user_author = $user->id;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."product_fixed_price (";
		$sql .= "fk_product, multicurrency_code, fixed_price_ht, fixed_price_ttc,";
		$sql .= " price_base_type, enabled, divergence_threshold, date_creation,";
		$sql .= " fk_user_author, entity";
		$sql .= ") VALUES (";
		$sql .= ((int) $this->fk_product);
		$sql .= ", '".$this->db->escape($this->multicurrency_code)."'";
		$sql .= ", ".($this->fixed_price_ht !== null ? ((float) $this->fixed_price_ht) : "NULL");
		$sql .= ", ".($this->fixed_price_ttc !== null ? ((float) $this->fixed_price_ttc) : "NULL");
		$sql .= ", '".$this->db->escape($this->price_base_type)."'";
		$sql .= ", ".((int) $this->enabled);
		$sql .= ", ".($this->divergence_threshold !== null && $this->divergence_threshold !== '' ? ((float) $this->divergence_threshold) : "NULL");
		$sql .= ", '".$this->db->idate($this->date_creation)."'";
		$sql .= ", ".((int) $this->fk_user_author);
		$sql .= ", ".((int) $this->entity);
		$sql .= ")";

		$this->db->begin();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX."product_fixed_price");
		$this->db->commit();
		return $this->id;
	}

	/**
	 * Update an existing fixed price record.
	 *
	 * @param  User $user User performing the action
	 * @return int        1 on success, <0 on error
	 */
	public function update($user)
	{
		$this->fk_user_modif = $user->id;

		$sql = "UPDATE ".MAIN_DB_PREFIX."product_fixed_price SET";
		$sql .= " fixed_price_ht = ".($this->fixed_price_ht !== null ? ((float) $this->fixed_price_ht) : "NULL");
		$sql .= ", fixed_price_ttc = ".($this->fixed_price_ttc !== null ? ((float) $this->fixed_price_ttc) : "NULL");
		$sql .= ", price_base_type = '".$this->db->escape($this->price_base_type)."'";
		$sql .= ", enabled = ".((int) $this->enabled);
		$sql .= ", divergence_threshold = ".($this->divergence_threshold !== null && $this->divergence_threshold !== '' ? ((float) $this->divergence_threshold) : "NULL");
		$sql .= ", fk_user_modif = ".((int) $this->fk_user_modif);
		$sql .= " WHERE rowid = ".((int) $this->id);

		$this->db->begin();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Toggle the enabled state of a fixed price.
	 *
	 * @param  User $user User performing the action
	 * @return int        1 on success, <0 on error
	 */
	public function toggleEnabled($user)
	{
		$this->enabled = $this->enabled ? 0 : 1;
		$this->fk_user_modif = $user->id;

		$sql = "UPDATE ".MAIN_DB_PREFIX."product_fixed_price SET";
		$sql .= " enabled = ".((int) $this->enabled);
		$sql .= ", fk_user_modif = ".((int) $this->fk_user_modif);
		$sql .= " WHERE rowid = ".((int) $this->id);

		$this->db->begin();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Delete a fixed price record.
	 *
	 * @param  User $user User performing the action
	 * @return int        1 on success, <0 on error
	 */
	public function delete($user)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."product_fixed_price";
		$sql .= " WHERE rowid = ".((int) $this->id);

		$this->db->begin();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Populate object properties from a DB row object.
	 *
	 * @param  object $obj Database row
	 * @return void
	 */
	private function _setFromObj($obj)
	{
		$this->id = $obj->rowid;
		$this->fk_product = $obj->fk_product;
		$this->multicurrency_code = $obj->multicurrency_code;
		$this->fixed_price_ht = $obj->fixed_price_ht;
		$this->fixed_price_ttc = $obj->fixed_price_ttc;
		$this->price_base_type = $obj->price_base_type;
		$this->enabled = $obj->enabled;
		$this->divergence_threshold = $obj->divergence_threshold;
		$this->date_creation = $obj->date_creation;
		$this->fk_user_author = $obj->fk_user_author;
		$this->fk_user_modif = $obj->fk_user_modif;
		$this->entity = $obj->entity;
	}
}
