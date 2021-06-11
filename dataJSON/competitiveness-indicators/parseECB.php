<?php

ignore_user_abort(TRUE);
set_time_limit(0);
header('Content-Type: text/plain');

$interval = (isset($_REQUEST['interval']) && preg_match('/^P[A-Z0-9:-]+$/', $_REQUEST['interval'])) ? $_REQUEST['interval'] : 'P1D';
$force = (isset($_REQUEST['force']) && $_REQUEST['force'] === 'yes');

define('PROJECTS_DIR', __DIR__.'/../../wizardProjects');

require_once __DIR__.'/../../urlMapperConfig.php';
require_once __DIR__.'/../../../../03/dataFetcher/ParseDotStatXML.php';
require_once __DIR__.'/../../../../03/libsPHP/CalcJSON.php';

function setUpCurl() {
  $ch = curl_init();
  if ($ch === FALSE) throw new Exception("could not initialize curl");
  $options = array(
    CURLOPT_CAINFO => __DIR__.'/../../../../vendors/curl-ca-bundle/src/ca-bundle.crt',
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_FAILONERROR => TRUE,
    CURLOPT_FILETIME => TRUE,
    CURLINFO_HEADER_OUT => TRUE,
    CURLOPT_ENCODING => '', // any compression type supported by curl
  );
  if (!curl_setopt_array($ch, $options)) throw new Exception("could not set curl options");
  return $ch;
}

function getIfModified($ch, $url, $date, $log = NULL) {
  $headers = '';
  $options = array(
    CURLOPT_URL => $url,
    CURLOPT_HEADERFUNCTION => function($ch, $data) use(&$headers) {
      $headers .= $data;
      return strlen($data);
    },
  );
  if ($date !== NULL) {
    $options[CURLOPT_TIMEVALUE] = $date->getTimestamp();
    $options[CURLOPT_TIMECONDITION] = CURL_TIMECOND_IFMODSINCE;
  } else {
    $options[CURLOPT_TIMECONDITION] = CURL_TIMECOND_NONE;
  }
  if (!curl_setopt_array($ch, $options)) throw new Exception("could not set curl options");
  $res = curl_exec($ch);
  if ($log !== NULL) {
    file_put_contents($log, array(
      gmdate(DateTime::ATOM),
      ' ',
      curl_getinfo($ch, CURLINFO_LOCAL_IP),
      ':',
      curl_getinfo($ch, CURLINFO_LOCAL_PORT),
      ' -> ',
      curl_getinfo($ch, CURLINFO_PRIMARY_IP),
      ':',
      curl_getinfo($ch, CURLINFO_PRIMARY_PORT),
      "\n\n",
      curl_getinfo($ch, CURLINFO_HEADER_OUT),
      $headers,
    ));
  }
  if ($res === FALSE) throw new Exception("curl error: ".curl_error($ch));
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $time = curl_getinfo($ch,  CURLINFO_FILETIME);
  if ($status === 304) {
    return array(NULL, NULL);
  } else {
    if ($time === -1) {
      if (preg_match('/\r\nDate: ([^\r]*)\r\n/', $headers, $matches)) {
        $time = new DateTime($matches[1]);
      } else {
        $time = NULL;
      }
    } else {
      $time = new DateTime("@$time");
    }
    return array($res, $time);
  }
}

function combineCurrencies($data) {
  $keyMap = $data->keyMap();
  $cs = $keyMap['CURRENCY'];
  sort($cs);
  // $cs should now contain: "Ex", "Hxx"
  $ts = $keyMap['EXR_TYPE'];
  sort($ts);
  // $ts should now contain: "Exxx", "Nxxx"
  // set EUR values in Hxx/Nxxx from Ex/Exxx
  $data->updateValues(function($v, $idx) use($cs, $ts, $data) {
    if ($idx['CURRENCY_DENOM'] == 'EUR'
        && $idx['CURRENCY'] == $cs[1]
        && $idx['EXR_TYPE'] == $ts[1]) {
      $idx['CURRENCY'] = $cs[0];
      $idx['EXR_TYPE'] = $ts[0];
      return $data->atIndex($idx);
    } else {
      return $v;
    }
  });
  return $data->filter(array(
    'CURRENCY' => $cs[1],
    'EXR_TYPE' => $ts[1],
  ));
}

function refreshData($oldData, $xmlStr, $countryMap) {
  $parser = new OECDDataParser();
  $data = new DC();
  $data->fromJSON($parser->parseXML($xmlStr)->toJSON());
  unset($parser);
  $data = combineCurrencies($data);
  $keyMap = $data->keyMap();
  $location = NULL;
  $location2 = NULL;
  $year = NULL;
  foreach ($keyMap as $dim => $keys) {
    if (count($keys) != 1) {
      if (isset($countryMap[$keys[0]])) {
        if ($location !== NULL) throw new Exception("multiple candidates for LOCATION");
        $location = $dim;
      } else {
        if ($year !== NULL) throw new Exception("multiple candidates for YEAR");
        $year = $dim;
      }
    }
  }
  if ($location === NULL) throw new Exception("no candidate for LOCATION");
  if ($year === NULL) throw new Exception("no candidate for YEAR");
  unset($keyMap[$location]);
  unset($keyMap[$year]);
  foreach ($keyMap as $dim => &$keys) {
    $keys = $keys[0];
  }
  $data = $data->filter($keyMap);
  $data->orderDimensions(array($location, $year));
  $newData = $data->toArray();
  unset($data);
  $newData['dimensions'] = array('LOCATION', 'YEAR');
  $newData['keys'][0] = array_map(function ($iso2) use($countryMap) {
    if (isset($countryMap[$iso2])) return $countryMap[$iso2];
    else throw new Exception("country code $iso2 not found");
  }, $newData['keys'][0]);
  $newData = array_replace($oldData, $newData);
  return $newData;
}

function updateProjects($projectsWizard, $urls, $upto, $force, $ConfigEdit) {
  $countryMap = array("EUR"=>"EUR","ATS"=>"AUT","BEF"=>"BEL","CYP"=>"CYP","DEM"=>"DEU","EEK"=>"EST","ESP"=>"ESP","FIM"=>"FIN","FRF"=>"FRA","GRD"=>"GRC","IEP"=>"IRL","ITL"=>"ITA","LTL"=>"LTU","LUF"=>"LUX","LVL"=>"LVA","MTL"=>"MLT","NLG"=>"NLD","PTE"=>"PRT","SIT"=>"SVN","SKK"=>"SVK");
  $ch = setUpCurl();
  foreach ($projectsWizard as $project) {
    $file = PROJECTS_DIR . '/config/' . $project . '.json';
    if (!file_exists($file)) {
      continue;
    }
    $config = json_decode(file_get_contents($file), TRUE);
    $updated = FALSE;
    $updatedData = array();
    foreach ($config['charts'] as $chartID => $chart) {
      if (isset($chart['data']['url'])) continue;
      if (!isset($urls[$chartID])) continue;
      $url = $urls[$chartID];
      $url = preg_replace('/[[:space:]]/', '', $url);
      $fetchDate = isset($chart['data']['fetchDate']) ? $chart['data']['fetchDate'] : NULL;
      if ($force) {
        echo "forced updated, ignoring fetchDate $fetchDate\n";
        $fetchDate = NULL;
      }
      $refresh = TRUE;
      $date = NULL;
      if (isset($fetchDate)) {
        $date = new DateTime($fetchDate);
        $refresh = $date < $upto;
      }
      $data = $chart['data'];
      echo "$project $chartID: ";
      if ($refresh) {
        echo "too old, checking for updates\n";
        flush();
        $now = new DateTime();
        $log = NULL;
        if (defined('LOGFILE_TEMPLATE')) {
          $log = sprintf(LOGFILE_TEMPLATE, $project, $chartID);
          if (!file_exists(dirname($log) . '/')) {
            mkdir(dirname($log), 0777, TRUE);
          }
        }
        try {
          list($res, $time) = getIfModified($ch, $url, $date, $log);
          if ($res === NULL) {
            echo "no updates in SDW\n";
          } else {
            if ($time === NULL) {
              echo "WARNING: no timestamp in response, using current local time\n";
              $time = $now;
            }
            $newData = refreshData($data, $res, $countryMap);
            $sameData = ($newData === $data);
            $newData['fetchDate'] = gmdate('Y-m-d\TH:i:s', $time->getTimeStamp()) . '.000Z';
            $sameTimestamp = ($newData['fetchDate'] === $fetchDate);
            if ($sameData && !$sameTimestamp) {
              echo "WARNING: new data matches old data, only updating timestamp\n";
            }
            if (!$sameData && $sameTimestamp) {
              echo "WARNING: new data with same timestamp\n";
              error_log(__FUNCTION__." ($project $chartID): new data with same timestamp");
            }
            if ($sameData  && $sameTimestamp) {
              echo "same data with same timestamp in SDW\n";
            } else {
              $config['charts'][$chartID]['data'] = $newData;
              $updated = TRUE;
            }
            if (!$sameData) {
              $tabTitle = '';
              foreach ($config['tabs'] as $tab) {
                if (in_array($chartID, $tab['charts'])) {
                  $tabTitle = $tab['title']['en'] . ' - ';
                  break;
                }
              }
              $updatedData[] = strip_tags($tabTitle . $config['charts'][$chartID]['title']['en']);
            }
          }
        } catch (Exception $e) {
          echo "ERROR: caught exception while updating: ", $e->getMessage(), "\n";
          error_log(__FUNCTION__." ($project $chartID): caught exception while updating: ".$e->getMessage());
        }
      } else {
        echo "up to date\n";
      }
    }
    if ($updated) {
      echo "$project updated\n";
      $tmp = $file . '.' . getmypid();
      file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
      rename($tmp, $file);
      if (count($updatedData)) {
        error_log(__FUNCTION__.": $project updated");
        if (isset($ConfigEdit['updatesEmail'])) {
          $subject = "automatic update: $project";
          $body = "$project updated:\n" . implode("\n", $updatedData);
          $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
          $headers  = implode("\r\n", array(
            'MIME-Version: 1.0',
            'Content-type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: base64',
          ));
          mail($ConfigEdit['updatesEmail'], $subject, base64_encode($body), $headers);
        }
      }
    }
    unset($config);
    flush();
  }
  curl_close($ch);
}

$start = microtime(TRUE);
$upto = (new DateTime('now'))->sub(new DateInterval($interval));
if ($force) {
  echo "Renewing all data for 1 project.\n";
} else {
  echo "Checking 1 project for data updates up to ", $upto->format(DateTime::ATOM), ".\n";
}
updateProjects(array('competitiveness-indicators'),
  array("EXR.M.H10..NRC0.A" => "https://sdw-wsrest.ecb.europa.eu/service/data/EXR/M.E7+H10.EUR+ATS+BEF+CYP+DEM+EEK+ESP+FIM+FRF+GRD+IEP+ITL+LTL+LUF+LVL+MTL+NLG+PTE+SIT+SKK.ERC0+NRC0.A?startPeriod=2000",
        "EXR.Q.H11..NRD0.A" => "https://sdw-wsrest.ecb.europa.eu/service/data/EXR/Q.E5+H11.EUR+ATS+BEF+CYP+DEM+EEK+ESP+FIM+FRF+GRD+IEP+ITL+LTL+LUF+LVL+MTL+NLG+PTE+SIT+SKK.ERD0+NRD0.A?startPeriod=2000",
        "EXR.Q.H11..NRU1.A" => "https://sdw-wsrest.ecb.europa.eu/service/data/EXR/Q.E5+H11.EUR+ATS+BEF+CYP+DEM+EEK+ESP+FIM+FRF+GRD+IEP+ITL+LTL+LUF+LVL+MTL+NLG+PTE+SIT+SKK.ERU1+NRU1.A?startPeriod=2000"),
  $upto,
  $force,
  $ConfigEdit);
echo "TOTAL TIME: ", microtime(TRUE)-$start, " seconds\n";
