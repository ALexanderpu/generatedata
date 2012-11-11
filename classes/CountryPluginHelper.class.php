<?php

/**
 * Enter description here ...
 */

class CountryPluginHelper {

	/**
	 * This returns all those countries in the database that have country-specific data, namely:
	 * region info (province / state / county), postal code info AND a list of cities. These countries
	 * have special functionality within the UI.
	 *
	 * @param boolean $installedOnly this determines
	 * @return array
	 */
	public function getCountryPlugins($installedOnly = true) {
		$allowedCountries = array();
		if ($installedOnly) {
			$installedCountries = Settings::getSetting("installedCountries");
			$allowedCountries = explode(",", $installedCountries);
		}

		$countryPluginsFolder = realpath(dirname(__FILE__) . "/../plugins/countries");
		$countryPlugins = array();

		if ($handle = opendir($countryPluginsFolder)) {
			while (false !== ($item = readdir($handle))) {
				if ($item == "." || $item == ".." || $item == ".svn") {
					continue;
				}
				if (!empty($allowedCountries) && !in_array($item, $allowedCountries)) {
					continue;
				}

				if (is_dir("$countryPluginsFolder/$item")) {
					$obj = self::instantiateCountryPlugin($countryPluginsFolder, $item);
					if ($obj != null) {
						$obj->path = $countryPluginsFolder;
						$obj->folder = $item;
						$countryPlugins[] = $obj;
					}
				}
			}
			closedir($handle);
		}

		return $countryPlugins;
	}


	private function instantiateCountryPlugin($baseFolder, $countryPluginFolder) {
		$countryPluginClassFileName = "{$countryPluginFolder}.class.php";
		if (!is_file("$baseFolder/$countryPluginFolder/$countryPluginClassFileName")) {
			return false;
		}

		try {
			include("$baseFolder/$countryPluginFolder/$countryPluginClassFileName");
		} catch (Exception $e) {
			return false;
		}

		$className = "Country_$countryPluginFolder";
		if (!class_exists($className)) {
			return false;
		}

		$instance = null;
		try {
			$instance = new $className();
		} catch (Exception $e) {
			return false;
		}

		// enforce inheritance of the abstract CountryPlugin class
		if (!($instance instanceof CountryPlugin)) {
			return false;
		}

		return $instance;
	}


	/**
	 * Helper function for country plugins to populate the database by simply passing the country data.
	 *
	 * @param string $countryName
	 * @param string $countrySlug
	 * @param array $data the data needs to be in the following format.
	 * 		$data = array(
	 *          array(
	 *              "regionName" => "",
	 *              "regionShort" => "",
	 *              "regionSlug" => "",
	 *              "weight" => ".",
	 *              "cities" => array(
	 *          	    "A", "B", "C"
	 *              )
	 *          ),
	 *          ...
	 *      );
	 */
	public static function populateDB($countryName, $countrySlug, $data) {
		$prefix = Core::getDbTablePrefix();

		// now insert the data
		$queries = array();
		$queries[] = "INSERT INTO {$prefix}countries (country, country_slug) VALUES ('$countryName', '$countrySlug')";

		foreach ($data as $regionInfo) {
			$currRegionName = $regionInfo["regionName"];
			$currRegionSlug = $regionInfo["regionSlug"];
			$queries[] = "
				INSERT INTO {$prefix}regions (country_slug, region, region_slug, region_short, weight)
				VALUES ('$countrySlug', '$currRegionName', '$currRegionSlug', '{$regionInfo["regionShort"]}', '{$regionInfo["weight"]}')
			";

			$rows = array();
			foreach ($regionInfo["cities"] as $cityName) {
				$cityName = addslashes($cityName);
				$rows[] = "('$countrySlug', '$currRegionSlug', '$cityName')";
			}
			$rowsStr = implode(",", $rows);
			$queries[] = "
				INSERT INTO {$prefix}cities (country_slug, region_slug, city)
				VALUES $rowsStr
			";
		}

		$response = Core::$db->query($queries);
		if ($response["success"]) {
			return array(true, "");
		} else {
			$this->uninstall();
			return array(false, $response["errorMessage"]);
		}
	}
}
