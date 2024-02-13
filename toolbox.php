<?php

/*
 * $Date: 2021/07/30 08:25:27 $
 * Written by Kjeld Borch Egevang
 * E-mail: kjeld@mail4us.dk
 */


class Toolbox extends Module
{
  public function __construct()
  {
    $this->v14 = _PS_VERSION_ >= "1.4.0.0";
    $this->v15 = _PS_VERSION_ >= "1.5.0.0";
    $this->name = 'toolbox';
    if ($this->v14)
      $this->tab = 'administration';
    else
      $this->tab = 'Tools';
    $this->version = '0.10';
    $this->author = 'Kjeld Borch Egevang';

    parent::__construct();

    $this->displayName = $this->l('Toolbox');
    $this->description = $this->l('Tools to make life easier with PrestaShop');
    $this->tables = array(
      _DB_PREFIX_.'connections',
      _DB_PREFIX_.'connections_page',
      _DB_PREFIX_.'connections_source',
      _DB_PREFIX_.'page_viewed'
    );
    $this->logTxt = '';
  }

  public function install()
  {
    return parent::install();
  }

  public function uninstall()
  {
    return parent::uninstall();
  }

  public function getDbContent()
  {
    $total = $this->getDatabaseSize();
    $stats = $this->getDatabaseSize($this->tables);
    $html = '';
    $html .= sprintf("<table class='table' style='float:right'>");
    $html .= sprintf("<tr><th colspan='2'><h3><center>%s</center></h3></th></tr>", $this->l('Size overview'));
    $html .= sprintf("<tr><th>%s</th><td>%s</td></tr>",
      $this->l('Database name'), _DB_NAME_);
    $html .= sprintf("<tr><th>%s</th><td>%.2f MiB</td></tr>",
      $this->l('Statistics (used)'), $stats['Data_total'] / 1024 / 1024);
    $html .= sprintf("<tr><th>%s</th><td>%.2f MiB</td></tr>",
      $this->l('Total size (used)'), $total['Data_total'] / 1024 / 1024);
    $html .= sprintf("<tr><th>%s</th><td>%d</td></tr>",
      $this->l('Total rows'), $total['Rows']);
    $html .= sprintf("<tr><th>%s</th><td>%d</td></tr>",
      $this->l('Average row size'), $total['Avg_row_length']);
    $html .= '</table>';
    return $html;
  }

  public function getDatabaseSize($tables = NULL)
  {
    $fields = array('Rows', 'Avg_row_length', 'Data_length', 'Index_length', 'Data_free');
    $rows = Db::getInstance()->ExecuteS('SHOW TABLE STATUS');
    $result = array();
    foreach ($fields as $field)
      $result[$field] = 0;
    foreach ($rows as $row) {
      if ($tables && !in_array($row['Name'], $tables))
	continue;
      foreach ($fields as $field)
	$result[$field] += $row[$field];
    }
    $result['Data_total'] = $result['Data_length'] + $result['Index_length'];
    return $result;
  }

  public function query($sql)
  {
    if ($this->v15)
      return Db::getInstance()->query($sql);
    else
      return Db::getInstance()->Execute($sql);
  }

  public function getImageFiles($selectInactive)
  {
    $this->fileNames = array();
    if ($selectInactive)
      $where = 'WHERE p.active = 1 ';
    else
      $where = '';
    $res = $this->query('SELECT p.`id_product`, `id_image` FROM `'.
      _DB_PREFIX_.'product` p LEFT JOIN `'._DB_PREFIX_.'image` i ON p.`id_product` = i.`id_product` '.
      $where.
      'ORDER BY p.`id_product`, `id_image`');
    if (!$res) {
      $this->_html .= sprintf("<p>%s</p>", Db::getInstance()->getMsgError());
      return false;
    }
    while ($row = Db::getInstance()->nextRow($res)) {
      if ($row['id_image']) {
	$fileName = $this->getImageName($row['id_product'], $row['id_image']);
	$this->fileNames[] = $fileName;
	// print $fileName.'<br />';
      }
    }
    return true;
  }

  public function getImageName($id_product, $id_image, $type = NULL)
  {
    if (Configuration::get('PS_LEGACY_IMAGES') !== '0') {
      $imagePath = _PS_PROD_IMG_DIR_.$id_product.'-'.$id_image;
    }
    else {
      $imagePath = _PS_PROD_IMG_DIR_.Image::getImgFolderStatic($id_image).$id_image;
    }
    return $imagePath.($type ? '-'.$type : '');
  }

  public function checkImgDir($dirName, &$count)
  {
    $imagesTypes = ImageType::getImagesTypes('products');
    foreach ($this->fileNames as $imagePath) {
      foreach ($imagesTypes as $k => $imageType) {
	$fileName = $imagePath.'-'.stripslashes($imageType['name']).'.jpg';
	if (!file_exists($fileName)) {
	  $this->logTxt .= 'missing '.$fileName.'<br />';
	  $count++;
	}
      }
    }
  }

  public function deleteImgDir($dirName, &$count)
  {
    if (microtime(true) - $this->start_time > $this->max_time) {
      $this->time_exceeded = true;
      return;
    }
    $res = opendir($dirName);
    if (!$res)
      die("Can't open image directory");
    $dirCount = 0;
    $indexFound = false;
    while (false !== ($fileName = readdir($res))) {
      $dirCount++;
      if (preg_match('/^(\d+(-\d+)?)/', $fileName, $matches)) {
	if (is_dir($dirName.$fileName)) {
	  $del = $this->deleteImgDir($dirName.$fileName.'/', $count);
	  if ($del)
	    $dirCount--;
	}
	else {
	  $fName = $dirName.$matches[1];
	  // print $fName.'<br >';
	  if (!in_array($fName, $this->fileNames)) {
	    $this->logTxt .= 'unlink '.$dirName.$fileName.'<br />';
	    if (unlink($dirName.$fileName)) {
	      $count++;
	      $dirCount--;
	    }
	    else
	      die("Unlink $fileName failed");
	  }
	}
      }
      elseif ($fileName == 'index.php') {
	if ($dirName != _PS_PROD_IMG_DIR_)
	  $indexFound = true;
      }
    }
    closedir($res);
    if ($dirCount <= 3 && $indexFound) {
      $fileName = 'index.php';
      $this->logTxt .= 'unlink '.$dirName.$fileName.'<br />';
      if (unlink($dirName.$fileName))
	$dirCount--;
      else
	die("unlink $fileName failed");
    }
    if ($dirCount <= 2) {
      $this->logTxt .= 'rmdir '.$dirName.'<br />';
      if (!rmdir($dirName))
	die("rmdir $dirName failed");
      return true; // Directory was deleted
    }
    return false; // Directory was not deleted
  }

  public function getContent()
  {
    $defLang = intval(Configuration::get('PS_LANG_DEFAULT'));
    if ($this->v15)
      $id_country = Context::getContext()->country->id;
    elseif ($this->v14)
      $id_country = Country::getDefaultCountryId();
    else {
      global $defaultCountry;
      $id_country = $defaultCountry->id;
    }
    $countryName = Country::getNameById($defLang, $id_country);
    $this->_html = '<h2>Toolbox</h2>';

    if (isset($_POST['submitCountries'])) {
      $res = $this->query('
	UPDATE '._DB_PREFIX_.'country SET active = 0 WHERE id_country != '.$id_country);
      if (!$res) {
	$this->_html .= sprintf("<p>%s</p>", Db::getInstance()->getMsgError());
      }
      $count = Db::getInstance()->Affected_Rows();
      if ($count == 1)
	$this->_html .= sprintf("<p class='conf'>%d %s</p>", $count, $this->l('row affected.'));
      else
	$this->_html .= sprintf("<p class='conf'>%d %s</p>", $count, $this->l('rows affected.'));
    }
    $this->_html .= '
      <form action="'.$_SERVER['REQUEST_URI'].'" method="post">
      <fieldset>';
    $this->_html .= '
      <img src="../modules/toolbox/toolbox.png" style="float:left; margin-right:15px;" />
      <b>'.$this->l('Disable all countries for shipping (except').' '.$countryName.')</b>
      <br /><br /><br /><br />';
    $this->_html .= '
      <input type="submit" name="submitCountries" value="'.$this->l('Disable').'" class="button" />';
    $this->_html .= '
      </fieldset>
      </form><br /><br />';

    if ($this->v14) {
      if (isset($_POST['submitSort'])) {
	$res = $this->query('SELECT c.`id_category`, c.`id_parent`, c.`position`, cl.`name`
	  FROM `'. _DB_PREFIX_.'category` c
	  LEFT JOIN `'. _DB_PREFIX_.'category_lang` cl
	  ON c.`id_category` = cl.`id_category`
	  WHERE cl.`id_lang` = '.$defLang);
	$categories = array();
	$positions = array();
	$names = array();
	while ($row = Db::getInstance()->nextRow($res)) {
	  $categories[$row['id_category']] = $row;
	  $positions[$row['id_category']] = 0;
	  $names[$row['id_category']] = $row['name'];
	}
	asort($names);
	$count = 0;
	if ($this->v15) {
	  foreach ($names as $id_category => $name) {
	    $id_parent = $categories[$id_category]['id_parent'];
	    if (empty($positions[$id_parent]))
	      $positions[$id_parent] = 1;
	    $categories[$id_category]['position'] = $positions[$id_parent];
	    $res = $this->query('UPDATE `'._DB_PREFIX_.'category_shop`
		SET `position` = '.$positions[$id_parent].'
		WHERE `id_category` = '.$id_category);
	    $positions[$id_parent] += 1;
	    $count++;
	  }
	}
	else {
	  foreach ($names as $id_category => $name) {
	    $id_parent = $categories[$id_category]['id_parent'];
	    if (empty($positions[$id_parent]))
	      $positions[$id_parent] = 1;
	    $categories[$id_category]['position'] = $positions[$id_parent];
	    $res = $this->query('UPDATE `'._DB_PREFIX_.'category`
		SET `position` = '.$positions[$id_parent].'
		WHERE `id_category` = '.$id_category);
	    $positions[$id_parent] += 1;
	    $count++;
	  }
	}
	$categoryObj = new Category(1); // Home category
	Category::regenerateEntireNtree();
	Module::hookExec('categoryUpdate', array('category' => $categoryObj));
	if ($count == 1)
	  $this->_html .= sprintf("<p class='conf'>%d %s</p>", $count, $this->l('category affected.'));
	else
	  $this->_html .= sprintf("<p class='conf'>%d %s</p>", $count, $this->l('categories affected.'));
      }
      $this->_html .= '
	<form action="'.$_SERVER['REQUEST_URI'].'" method="post">
	<fieldset>';
      $this->_html .= '
	<img src="../modules/toolbox/categorysort.png" style="float:left; margin-right:15px;" />
	<b>'.$this->l('Sort categories alphabetically based on default language').'</b>
	<br /><br /><br /><br />';
      $this->_html .= '
	<input type="submit" name="submitSort" value="'.$this->l('Sort').'" class="button" />';
      $this->_html .= '
	</fieldset>
	</form><br /><br />';
    }

    if (isset($_POST['submitRemove'])) {
      $count = 0;
      foreach ($this->tables as $table) {
	$res = $this->query('TRUNCATE '.$table);
	if (!$res) {
	  $this->_html .= sprintf("<p>%s</p>", Db::getInstance()->getMsgError());
	}
	$res = $this->query('OPTIMIZE TABLE '.$table);
	if (!$res) {
	  $this->_html .= sprintf("<p>%s</p>", Db::getInstance()->getMsgError());
	}
      }
    }
    $this->_html .= '
      <form action="'.$_SERVER['REQUEST_URI'].'" method="post">
      <fieldset>';
    $this->_html .= $this->getDbContent();
    $this->_html .= '
      <img src="../modules/toolbox/trash.png" style="float:left; margin-right:15px;" />
      <b>'.$this->l('Remove connection statistics').'</b>
      <br /><br /><br /><br />';
    $this->_html .= '
      <input type="submit" name="submitRemove" value="'.$this->l('Remove').'" class="button" />';
    $this->_html .= '
      </fieldset>
      </form><br /><br />';

    $checkInactive = Tools::getValue('checkInactive');
    if (isset($_POST['checkImages'])) {
      if ($this->getImageFiles($checkInactive)) {
	$count = 0;
	$this->checkImgDir(_PS_PROD_IMG_DIR_, $count);
	if ($count == 1)
	  $txt = $this->l('image file missing.');
	else
	  $txt = $this->l('image files missing.');
	if ($this->logTxt)
	  $txt .= '<hr />';
	$this->_html .= sprintf("<p class='conf'>%d %s%s</p>", $count, $txt, $this->logTxt);
      }
    }
    if ($checkInactive)
      $checked = "checked";
    else
      $checked = "";
    $this->_html .= '
      <form action="'.$_SERVER['REQUEST_URI'].'" method="post">
      <fieldset>';
    $this->_html .= '
      <img src="../modules/toolbox/check-images.png" style="float:left; margin-right:15px;" />
      <b>'.$this->l('Check images are present').'</b>
      <br /><br /><br />';
    $this->_html .= '
      <input name="checkInactive" id="checkInactive" type="checkbox" '.$checked.' /> '.$this->l('Do not check images from deactivated products').'
      <br /><br />
      <input type="submit" name="checkImages" value="'.$this->l('Check').'" class="button" />';
    $this->_html .= '
      </fieldset>
      </form><br /><br />';

    $deleteInactive = Tools::getValue('deleteInactive');
    if (isset($_POST['deleteImages'])) {
      if ($this->getImageFiles($deleteInactive)) {
	$count = 0;
        $this->max_time = 30;
        $this->start_time = microtime(true);
	$this->deleteImgDir(_PS_PROD_IMG_DIR_, $count);
        if (isset($this->time_exceeded))
            $this->logTxt .= 'max. time exceeded<br />';
	if ($count == 1)
	  $txt = $this->l('image file deleted.');
	else
	  $txt = $this->l('image files deleted.');
	if ($this->logTxt)
	  $txt .= '<hr />';
	$this->_html .= sprintf("<p class='conf'>%d %s%s</p>", $count, $txt, $this->logTxt);
      }
    }
    if ($deleteInactive)
      $checked = "checked";
    else
      $checked = "";
    $this->_html .= '
      <form action="'.$_SERVER['REQUEST_URI'].'" method="post">
      <fieldset>';
    $this->_html .= '
      <img src="../modules/toolbox/delete-images.png" style="float:left; margin-right:15px;" />
      <b>'.$this->l('Remove unused images').'</b>
      <br /><br /><br />';
    $this->_html .= '
      <input name="deleteInactive" id="deleteInactive" type="checkbox" '.$checked.' /> '.$this->l('Also remove images from deactivated products').'
      <br /><br />
      <input type="submit" name="deleteImages" value="'.$this->l('Remove').'" class="button" />';
    $this->_html .= '
      </fieldset>
      </form><br /><br />';

    return $this->_html;
  }
}

?>
