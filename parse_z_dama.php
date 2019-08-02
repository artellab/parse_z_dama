<?php

class parseZDama
{
    public $catalog_links = array();
    public $product_links = array();

    private $site = "https://www.z-dama.ru";
    private $gl_id = 1;
    private $arr_product = array();

    private $arr_include_extrude = array();

    public function getCatalogLinks()
    {
        // на всякий случай проверяем массивы
        if (!empty($this->product_links)) {
            $this->prepareProductLinks($this->product_links);
        }
        if (!empty($this->catalog_links)) {
            $this->prepareProductLinks($this->catalog_links);
        }

        // если пустой массив ссылок на карточки товаров, смотрим может задан массив на страницы со списком товаров
        if (!empty($this->product_links)) {
            // заполняем $arr_product товарами с размером и кол-вом
            $this->getProductSize($this->product_links);
        } else {
            if (!empty($this->catalog_links)) {
                foreach ($this->catalog_links as $link) {
                    // список ссылок на карточки товаров
                    $this->product_links = $this->getProductLinks($this->site . $link);

                    // заполняем $arr_product товарами с размером и кол-вом
                    $this->getProductSize($this->product_links);
                }
            } else {
                die('Не задан массив ссылок!');
            }
        }
    }

    // Получаем список ссылок на карточки товаров на странице
    // <a href="/catalog/platya_1/plate_5246_10_3/" class="product-preview__name">Платье 5246.10.3</a>
    private function getProductLinks($link)
    {
        $content = file_get_contents($link);
        preg_match_all('/<a href="(.+)" class="product-preview__name">/', $content, $match);
        return $match[1];
    }

    // Получаем размеры и наличие из карточек
    //<div class="catalog-element__sku-size-one catalog-element__sku-size-one--retail" data-size="54" data-amount="10" data-id="129574" data-in-basket="">54</div>
    //<div class="catalog-element__sku-size-one catalog-element__sku-size-one--retail active" data-size="50" data-amount="23" data-id="133873" data-in-basket="" > 50 </div>
    private function getProductSize($product_links)
    {
        foreach ($product_links as $link) {
            $content = file_get_contents($this->site . $link);
            $content = preg_replace("/\t|\r|\n/", "", $content);
            $content = preg_replace("/\s+/", " ", $content);

            // Можно сделать более универсально, на случай если поменяют местами атрибуты, но тогда думаю лучше применять dom
            preg_match_all('/<div class="catalog-element__sku-size-one catalog-element__sku-size-one([^"]*)" data-size="([0-9]+)" data-amount="([0-9]+)"([^>]*)>/', $content, $match);

            // $match[2] - size
            // $match[3] - count

            $product_info["id"] = $this->gl_id;
            $product_info["url"] = $this->site . $link;
            $product_info["size"] = $match[2];
            $product_info["quantity"] = $match[3];
            $this->gl_id++;

            // Если есть размеры
            if (!empty($product_info["size"]) && count($product_info["size"]) > 0) {
                array_push($this->arr_product, $product_info);
            }
        }
    }

    // Проверяем на дубли и удаляем одинаковые ссылки
    private function prepareProductLinks(&$array)
    {
        $new_array = array();
        foreach ($array as $element) {
            if (empty(trim($element))) {
                continue;
            }
            $element_finded = false;
            foreach ($new_array as $new_element) {
                if ($new_element == $element) {
                    $element_finded = true;
                }
            }
            if (!$element_finded) {
                array_push($new_array, $element);
            }
        }
        $array = $new_array;
    }

    // Формируем sql с полями productID, productUrl, sizeValue
    public function generateSQL()
    {
        $sql = "";
        foreach ($this->arr_product as $product) {
            // По ТЗ формируем размер в виде строки
            $size_string = implode(",", $product["size"]);
            $sql .= "INSERT INTO `Products` (`productID`, `productUrl`, `sizeValue`) VALUES (" . $product["id"] . ", '<a target=\"_blank\" href=\"" . $product["url"] . "\">" . $product["url"] . "</a>', '" . $size_string . "');<br />";
        }
        return $sql;
    }

    // Формируем sql с полями productID, productUrl, sizeValue, отдельными строками
    public function generateSQLMultiRowSize()
    {
        $sql = "";

        $this->prepareProductSizes($this->arr_product);

        foreach ($this->arr_product as $product) {
            // По ТЗ формируем размер в виде строки
            foreach ($product["size"] as $product_size) {
                $sql .= "INSERT INTO `Products` (`productID`, `productUrl`, `sizeValue`) VALUES (" . $product["id"] . ", '<a target=\"_blank\" href=\"" . $product["url"] . "\">" . $product["url"] . "</a>', '" . $product_size . "');<br />";
            }
        }
        return $sql;
    }

    // Проверяем на дубли по размерам и удаляем одинаковые размеры (но на сайте такого не увидел, также возникает конфликт какое количество брать? Пока наименьшее)
    public function prepareProductSizes(&$array)
    {
        $new_array = array();
        foreach ($array as $element) {

            $new_array_sizes = array();
            $new_array_quantity = array();

            for ($i = 0; $i < count($element["size"]); $i++) {

                $element_size = $element["size"][$i];
                $element_quantity = $element["quantity"][$i];
                $element_finded = false;

                for ($j = 0; $j < count($new_array_sizes); $j++) {
                    $new_element = $new_array_sizes[$j];
                    if ($new_element == $element_size) {
                        if ($element_quantity < $new_array_quantity[$j]) {
                            $new_array_quantity[$j] = $element_quantity;
                        }
                        $element_finded = true;
                    }
                }

                if (!$element_finded) {
                    array_push($new_array_sizes, $element_size);
                    array_push($new_array_quantity, $element_quantity);
                }
            }

            $element["size"] = $new_array_sizes;
            $element["quantity"] = $new_array_quantity;
            array_push($new_array, $element);

        }
        $array = $new_array;
    }

    // Формируем sql для включения/исключения из видимости на сайте
    public function generateSQLShowed($inc_ex = "отключение")
    {
        $sql = "";
        $type_inc_ex = 1; // массив с id на отключение
        $product_flag = 0;

        if ($inc_ex == "включение") {
            $type_inc_ex = 0;
            $product_flag = 1;
        }

        $sql = "UPDATE `Products` SET `productShowed` = " . $product_flag . " WHERE `id` IN (" . implode(",", $this->arr_include_extrude[$type_inc_ex]) . ");<br />";

        // Либо отдельными запросами
        /*
        foreach ($this->arr_include_extrude[$type_inc_ex] as $product_id) {
            $sql .= "UPDATE `Products` SET `productShowed` = " . $product_flag . " WHERE `id` = " . $product_id . ";<br />";
        }
        */
        return $sql;
    }

    // Получаем массив из 2х типов
    // на подключение - наличие от двух и более разных размеров, у которых в наличии более 5 штук,
    // на отключение - если условия не выполняются
    public function productIncExt()
    {
        $this->arr_include_extrude[0] = array();
        $this->arr_include_extrude[1] = array();
        //$arr_include_extrude[0] = array();
        //$arr_include_extrude[1] = array();

        foreach ($this->arr_product as $product) {
            $count_include = 0;
            // Считаем количество размеров подпадающих под условие
            for ($i = 0; $i < count($product["size"]) - 1; $i++) {

                $cur_size = (int)$product["size"][$i];
                $cur_size_count = (int)$product["quantity"][$i];

                // Если размер меньше или равен 5, то он уже не интересен
                if ($cur_size_count <= 5) {
                    continue;
                }
                for ($j = $i + 1; $j < count($product["size"]); $j++) {
                    $next_size = (int)$product["size"][$j];
                    $next_size_count = (int)$product["quantity"][$j];

                    // Найден разный размер
                    if ($cur_size != $next_size && $next_size_count > 5) {
                        $count_include++;
                        // если нужно считать более 2х, то убираем break и меняем значения в условии - оставил после теста
                        // для текущего условия в принципе можно сделать $count_include bool
                        break;
                    }
                }
            }
            if ($count_include >= 1) {
                array_push($this->arr_include_extrude[0], $product["id"]);
            } else {
                array_push($this->arr_include_extrude[1], $product["id"]);
            }
        }
        return $this->arr_include_extrude;
    }

}

$z_dama = new parseZDama;

// задаем массив ссылок с товарами
$z_dama->product_links = [
    "/catalog/platya_1/plate_5246_10_3/",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/bluzy_1/vodolazka_159_210_2/"
];

/*
 // test
$z_dama->product_links = [
    "/catalog/platya_1/plate_5246_10_3/",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/bluzy_1/vodolazka_159_210_2/"
];

$z_dama->product_links = [
    "",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/platya_1/plate_5670_210_12/",
    "/catalog/bluzy_1/vodolazka_159_210_2/"
];

// либо внутри категорий скрипт сам получает список товаров с 1й страницы указанных категорий (использовал для поиска товара для отключения)
$z_dama->catalog_links = [
    "/catalog/platya_1/",
    "/catalog/bluzy_1/"
];
*/

$z_dama->getCatalogLinks();

// действия с полученными товарами
$sql_insert = $z_dama->generateSQL();
$sql_insert_multisize = $z_dama->generateSQLMultiRowSize();
$product_include_extrude = $z_dama->productIncExt();

$sql_update_extrude = $z_dama->generateSQLShowed();
$sql_update_include = $z_dama->generateSQLShowed("включение");

echo "<h2>SQL запросы на insert:</h2><br />";
echo $sql_insert;

echo "<h2>SQL запросы на insert построчно:</h2><br />";
echo $sql_insert_multisize;


echo "<h2>ID товаров на включение:</h2><br />";
echo "<pre>";
print_r($product_include_extrude[0]);
echo "</pre>";

echo "<strong>SQL на включение товаров:</strong><br />";
echo "<pre>";
echo $sql_update_include;
echo "</pre>";


echo "<h2>ID товаров на отключение:</h2><br />";
echo "<pre>";
print_r($product_include_extrude[1]);
echo "</pre>";

echo "<strong>SQL на отключение товаров:</strong><br />";
echo "<pre>";
echo $sql_update_extrude;
echo "</pre>";



echo "<h2>Тест дублей в массиве по размерам и выбор наименьшего количества товаров:</h2><br />";

$arr[0]["url"] = "url";
$arr[0]["size"][0] = 1;
$arr[0]["size"][1] = 2;
$arr[0]["quantity"][0] = 1;
$arr[0]["quantity"][1] = 2;
$arr[1]["url"] = "url";
$arr[1]["size"][0] = 1;
$arr[1]["size"][1] = 2;
$arr[1]["size"][2] = 2;
$arr[1]["size"][3] = 3;
$arr[1]["size"][4] = 4;
$arr[1]["size"][5] = 4;
$arr[1]["quantity"][0] = 1;
$arr[1]["quantity"][1] = 2;
$arr[1]["quantity"][2] = 1;
$arr[1]["quantity"][3] = 5;
$arr[1]["quantity"][4] = 7;
$arr[1]["quantity"][5] = 3;

echo "<pre>";
print_r($arr);
echo "</pre>";

$z_dama->prepareProductSizes($arr);

echo "<pre>";
print_r($arr);
echo "</pre>";
