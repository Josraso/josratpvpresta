<?php
/**
 * josratpvpresta - catálogo para el TPV: búsqueda, escaneo, categorías, favoritos
 * y combinaciones de cualquier tipo (no solo talla/color).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpCatalog
{
    protected static $columns = [];

    protected static function hasColumn($table, $column)
    {
        $k = $table . '.' . $column;
        if (!isset(self::$columns[$k])) {
            self::$columns[$k] = (bool) Db::getInstance()->getValue('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'' . pSQL(_DB_PREFIX_ . $table) . '\' AND COLUMN_NAME = \'' . pSQL($column) . '\'');
        }

        return self::$columns[$k];
    }

    /** Campos de código que existen en esta versión (mpn solo desde 1.7.7) */
    protected static function codeFields()
    {
        $f = ['reference', 'ean13', 'upc', 'isbn'];
        if (self::hasColumn('product', 'mpn')) {
            $f[] = 'mpn';
        }

        return $f;
    }

    public static function imageType()
    {
        if (method_exists('ImageType', 'getFormattedName')) {
            return ImageType::getFormattedName('home');
        }

        return 'home_default';
    }

    public static function imageUrl($linkRewrite, $idProduct, $idImage)
    {
        if (!$idImage) {
            return '';
        }

        return Context::getContext()->link->getImageLink($linkRewrite ?: 'p', (string) (int) $idImage, self::imageType());
    }

    protected static function baseSelect()
    {
        $idShop = JtpTools::shopId();
        $idLang = JtpTools::langId();

        return 'SELECT p.id_product, pl.name, pl.link_rewrite, p.reference, p.ean13,
                COUNT(DISTINCT pa.id_product_attribute) AS combos,
                (SELECT i.id_image FROM ' . JtpTools::t('image_shop') . ' i WHERE i.id_product = p.id_product AND i.id_shop = ' . $idShop . ' AND i.cover = 1 LIMIT 1) AS id_image
            FROM ' . JtpTools::t('product') . ' p
            INNER JOIN ' . JtpTools::t('product_shop') . ' ps ON (ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . ')
            INNER JOIN ' . JtpTools::t('product_lang') . ' pl ON (pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . ')
            LEFT JOIN ' . JtpTools::t('product_attribute') . ' pa ON (pa.id_product = p.id_product)';
    }

    /**
     * Búsqueda por texto (todas las palabras deben coincidir en nombre o referencia)
     * o por código exacto (EAN, UPC, ISBN, MPN, referencia) de producto o combinación.
     */
    public static function search($q, $idCategory = 0, $page = 1)
    {
        $q = trim((string) $q);
        $limit = max(10, (int) JtpTools::cfg('JTP_SEARCH_LIMIT', 40));
        $offset = max(0, ((int) $page - 1) * $limit);
        $where = ['ps.active = 1'];
        if (!JtpTools::cfgBool('JTP_SHOW_OOS')) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . JtpTools::t('stock_available') . ' sa WHERE sa.id_product = p.id_product AND sa.quantity > 0)';
        }
        $join = '';
        $order = 'pl.name ASC';
        if ($idCategory) {
            $join = ' INNER JOIN ' . JtpTools::t('category_product') . ' cp ON (cp.id_product = p.id_product AND cp.id_category = ' . (int) $idCategory . ')';
            $order = 'MIN(cp.position) ASC';
        }
        if ($q !== '') {
            $or = [];
            $terms = array_filter(preg_split('/\s+/', $q));
            $and = [];
            foreach ($terms as $t) {
                $like = pSQL(str_replace(['%', '_'], ['\%', '\_'], $t));
                $and[] = '(pl.name LIKE \'%' . $like . '%\' OR p.reference LIKE \'%' . $like . '%\' OR pa.reference LIKE \'%' . $like . '%\')';
            }
            $or[] = '(' . implode(' AND ', $and) . ')';
            foreach (self::codeFields() as $f) {
                $or[] = 'p.`' . $f . '` = \'' . pSQL($q) . '\'';
                $or[] = 'pa.`' . $f . '` = \'' . pSQL($q) . '\'';
            }
            if (ctype_digit($q)) {
                $or[] = 'p.id_product = ' . (int) $q;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $sql = self::baseSelect() . $join . ' WHERE ' . implode(' AND ', $where) . '
            GROUP BY p.id_product ORDER BY ' . $order . ' LIMIT ' . (int) $offset . ', ' . (int) ($limit + 1);
        $rows = Db::getInstance()->executeS($sql) ?: [];
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }

        return ['products' => self::decorate($rows), 'more' => $more];
    }

    public static function decorate(array $rows)
    {
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id_product'];
            $out[] = [
                'id_product' => $id,
                'name' => $r['name'],
                'reference' => $r['reference'],
                'ean13' => $r['ean13'],
                'has_combinations' => (int) $r['combos'] > 0,
                'price' => JtpTools::round(Product::getPriceStatic($id, true, null, 6)),
                'stock' => (int) StockAvailable::getQuantityAvailableByProduct($id, 0),
                'image' => self::imageUrl($r['link_rewrite'], $id, (int) $r['id_image']),
            ];
        }

        return $out;
    }

    /**
     * Escaneo exacto. Devuelve la combinación concreta si el código es de una combinación.
     *
     * @return array|null ['id_product' => , 'id_product_attribute' => ]
     */
    public static function scan($code)
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        $db = Db::getInstance();
        $idShop = JtpTools::shopId();
        $conds = [];
        foreach (self::codeFields() as $f) {
            $conds[] = 'pa.`' . $f . '` = \'' . pSQL($code) . '\'';
        }
        $row = $db->getRow('SELECT pa.id_product, pa.id_product_attribute FROM ' . JtpTools::t('product_attribute') . ' pa
            INNER JOIN ' . JtpTools::t('product_shop') . ' ps ON (ps.id_product = pa.id_product AND ps.id_shop = ' . $idShop . ' AND ps.active = 1)
            WHERE ' . implode(' OR ', $conds));
        if ($row) {
            return ['id_product' => (int) $row['id_product'], 'id_product_attribute' => (int) $row['id_product_attribute']];
        }
        $conds = [];
        foreach (self::codeFields() as $f) {
            $conds[] = 'p.`' . $f . '` = \'' . pSQL($code) . '\'';
        }
        $row = $db->getRow('SELECT p.id_product FROM ' . JtpTools::t('product') . ' p
            INNER JOIN ' . JtpTools::t('product_shop') . ' ps ON (ps.id_product = p.id_product AND ps.id_shop = ' . $idShop . ' AND ps.active = 1)
            WHERE ' . implode(' OR ', $conds));
        if ($row) {
            return ['id_product' => (int) $row['id_product'], 'id_product_attribute' => 0];
        }

        return null;
    }

    /**
     * Ficha para el selector de variantes: grupos de atributos reales del producto,
     * sus valores y todas las combinaciones con precio, stock, códigos e imagen.
     */
    public static function product($idProduct, $idCustomer = null)
    {
        $idLang = JtpTools::langId();
        $idShop = JtpTools::shopId();
        $product = new Product((int) $idProduct, false, $idLang, $idShop);
        if (!Validate::isLoadedObject($product)) {
            throw new JtpException('Producto no encontrado');
        }
        $db = Db::getInstance();
        $rows = $db->executeS('SELECT pac.id_product_attribute, a.id_attribute, a.id_attribute_group, a.color, a.position AS a_pos,
                al.name AS value_name, ag.group_type, ag.is_color_group, ag.position AS g_pos, agl.public_name AS group_name,
                pa.reference, pa.ean13, pa.upc, pas.default_on
            FROM ' . JtpTools::t('product_attribute') . ' pa
            INNER JOIN ' . JtpTools::t('product_attribute_shop') . ' pas ON (pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . $idShop . ')
            INNER JOIN ' . JtpTools::t('product_attribute_combination') . ' pac ON pac.id_product_attribute = pa.id_product_attribute
            INNER JOIN ' . JtpTools::t('attribute') . ' a ON a.id_attribute = pac.id_attribute
            INNER JOIN ' . JtpTools::t('attribute_lang') . ' al ON (al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . ')
            INNER JOIN ' . JtpTools::t('attribute_group') . ' ag ON ag.id_attribute_group = a.id_attribute_group
            INNER JOIN ' . JtpTools::t('attribute_group_lang') . ' agl ON (agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ' . $idLang . ')
            WHERE pa.id_product = ' . (int) $idProduct . '
            ORDER BY ag.position, a.position') ?: [];

        $groups = [];
        $combos = [];
        foreach ($rows as $r) {
            $gid = (int) $r['id_attribute_group'];
            if (!isset($groups[$gid])) {
                $groups[$gid] = [
                    'id' => $gid,
                    'name' => $r['group_name'],
                    'type' => ((int) $r['is_color_group'] || $r['group_type'] === 'color') ? 'color' : 'select',
                    'position' => (int) $r['g_pos'],
                    'values' => [],
                ];
            }
            $aid = (int) $r['id_attribute'];
            if (!isset($groups[$gid]['values'][$aid])) {
                $texture = file_exists(_PS_COL_IMG_DIR_ . $aid . '.jpg') ? _THEME_COL_DIR_ . $aid . '.jpg' : '';
                $groups[$gid]['values'][$aid] = [
                    'id' => $aid,
                    'name' => $r['value_name'],
                    'color' => $r['color'],
                    'texture' => $texture,
                    'position' => (int) $r['a_pos'],
                ];
            }
            $ipa = (int) $r['id_product_attribute'];
            if (!isset($combos[$ipa])) {
                $combos[$ipa] = [
                    'id_product_attribute' => $ipa,
                    'attributes' => [],
                    'reference' => $r['reference'],
                    'ean13' => $r['ean13'],
                    'default' => (bool) $r['default_on'],
                ];
            }
            $combos[$ipa]['attributes'][$gid] = $aid;
        }

        $images = [];
        if ($combos) {
            $imgRows = $db->executeS('SELECT pai.id_product_attribute, pai.id_image FROM ' . JtpTools::t('product_attribute_image') . ' pai
                INNER JOIN ' . JtpTools::t('image') . ' i ON i.id_image = pai.id_image
                WHERE pai.id_product_attribute IN (' . implode(',', array_map('intval', array_keys($combos))) . ')
                ORDER BY i.position ASC') ?: [];
            foreach ($imgRows as $ir) {
                if (!isset($images[(int) $ir['id_product_attribute']])) {
                    $images[(int) $ir['id_product_attribute']] = (int) $ir['id_image'];
                }
            }
        }
        $cover = Product::getCover((int) $idProduct);
        $coverUrl = $cover ? self::imageUrl($product->link_rewrite, $idProduct, (int) $cover['id_image']) : '';
        foreach ($combos as $ipa => &$c) {
            $c['price'] = JtpTools::round(Product::getPriceStatic((int) $idProduct, true, $ipa, 6, null, false, true, 1, false, $idCustomer));
            $c['stock'] = (int) StockAvailable::getQuantityAvailableByProduct((int) $idProduct, $ipa);
            $c['image'] = isset($images[$ipa]) ? self::imageUrl($product->link_rewrite, $idProduct, $images[$ipa]) : $coverUrl;
        }
        unset($c);

        // Orden y limpieza de índices para JSON
        uasort($groups, function ($a, $b) {
            return $a['position'] - $b['position'];
        });
        foreach ($groups as &$g) {
            uasort($g['values'], function ($a, $b) {
                return $a['position'] - $b['position'];
            });
            $g['values'] = array_values($g['values']);
        }
        unset($g);

        return [
            'id_product' => (int) $idProduct,
            'name' => $product->name,
            'reference' => $product->reference,
            'image' => $coverUrl,
            'price' => JtpTools::round(Product::getPriceStatic((int) $idProduct, true, null, 6, null, false, true, 1, false, $idCustomer)),
            'stock' => (int) StockAvailable::getQuantityAvailableByProduct((int) $idProduct, 0),
            'groups' => array_values($groups),
            'combinations' => array_values($combos),
        ];
    }

    public static function categories($idParent = 0)
    {
        $idParent = $idParent ?: (int) Configuration::get('PS_HOME_CATEGORY');
        $cats = Category::getChildren((int) $idParent, JtpTools::langId(), true, JtpTools::shopId()) ?: [];
        $out = [];
        foreach ($cats as $c) {
            $out[] = ['id' => (int) $c['id_category'], 'name' => $c['name']];
        }
        $parent = new Category((int) $idParent, JtpTools::langId());

        return [
            'id' => (int) $idParent,
            'name' => $parent->name,
            'id_parent' => (int) $idParent === (int) Configuration::get('PS_HOME_CATEGORY') ? 0 : (int) $parent->id_parent,
            'children' => $out,
        ];
    }

    public static function favorites()
    {
        $idShop = JtpTools::shopId();
        $rows = Db::getInstance()->executeS(str_replace('WHERE', 'WHERE', self::baseSelect()) . '
            INNER JOIN ' . JtpTools::t('jtp_favorite') . ' f ON (f.id_product = p.id_product AND f.id_shop = ' . $idShop . ')
            WHERE ps.active = 1 GROUP BY p.id_product ORDER BY MIN(f.position) ASC, pl.name ASC LIMIT 200') ?: [];

        return self::decorate($rows);
    }

    public static function toggleFavorite($idProduct)
    {
        $db = Db::getInstance();
        $idShop = JtpTools::shopId();
        $exists = (int) $db->getValue('SELECT COUNT(*) FROM ' . JtpTools::t('jtp_favorite') . ' WHERE id_shop = ' . $idShop . ' AND id_product = ' . (int) $idProduct);
        if ($exists) {
            $db->delete('jtp_favorite', 'id_shop = ' . $idShop . ' AND id_product = ' . (int) $idProduct);

            return false;
        }
        $pos = (int) $db->getValue('SELECT IFNULL(MAX(position),0)+1 FROM ' . JtpTools::t('jtp_favorite') . ' WHERE id_shop = ' . $idShop);
        $db->insert('jtp_favorite', ['id_shop' => $idShop, 'id_product' => (int) $idProduct, 'position' => $pos]);

        return true;
    }

    public static function favoriteIds()
    {
        $rows = Db::getInstance()->executeS('SELECT id_product FROM ' . JtpTools::t('jtp_favorite') . ' WHERE id_shop = ' . JtpTools::shopId()) ?: [];

        return array_map('intval', array_column($rows, 'id_product'));
    }
}
