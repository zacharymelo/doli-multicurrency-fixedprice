-- Copyright (C) 2026 DPG Supply
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

ALTER TABLE llx_product_fixed_price ADD UNIQUE INDEX uk_product_fixed_price (fk_product, multicurrency_code, entity);
ALTER TABLE llx_product_fixed_price ADD INDEX idx_product_fixed_price_product (fk_product);
ALTER TABLE llx_product_fixed_price ADD INDEX idx_product_fixed_price_entity (entity);
ALTER TABLE llx_product_fixed_price ADD CONSTRAINT fk_product_fixed_price_product FOREIGN KEY (fk_product) REFERENCES llx_product(rowid);
