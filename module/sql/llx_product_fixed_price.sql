-- Copyright (C) 2026 DPG Supply
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

CREATE TABLE llx_product_fixed_price (
	rowid              INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_product         INTEGER       NOT NULL,
	multicurrency_code VARCHAR(3)    NOT NULL,
	fixed_price_ht     DOUBLE(24,8)  DEFAULT NULL,
	fixed_price_ttc    DOUBLE(24,8)  DEFAULT NULL,
	price_base_type    VARCHAR(3)    NOT NULL DEFAULT 'HT',
	enabled            TINYINT       NOT NULL DEFAULT 1,
	divergence_threshold DOUBLE(5,2) DEFAULT NULL,
	date_creation      DATETIME      NOT NULL,
	tms                TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_author     INTEGER,
	fk_user_modif      INTEGER,
	entity             INTEGER       NOT NULL DEFAULT 1
) ENGINE=innodb;
