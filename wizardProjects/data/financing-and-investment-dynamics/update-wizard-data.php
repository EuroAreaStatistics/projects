<?php
ignore_user_abort(TRUE);
set_time_limit(0);
header('Content-Type: text/plain');

$interval = (isset($_REQUEST['interval']) && preg_match('/^P[A-Z0-9:-]+$/', $_REQUEST['interval'])) ? $_REQUEST['interval'] : 'PT1H';
$force = (isset($_REQUEST['force']) && $_REQUEST['force'] === 'yes');
$warn = (isset($_REQUEST['warn']) && $_REQUEST['force'] === 'warn');
if (php_sapi_name() === 'cli') $warn = TRUE;

require_once __DIR__.'/../../../../../BaseURL.php';
require_once __DIR__.'/../../../liveProjects.php';
require_once __DIR__.'/../../../urlMapperConfig.php';

require_once __DIR__.'/../../../../../03/dataFetcher/ParseDotStatXML.php';
require_once __DIR__.'/../../../../../03/libsPHP/CalcJSON.php';
require_once __DIR__.'/../../../../../03/libsPHP/CalcJSON.php';


function warn($t) {
  global $warn;

  if ($warn) echo 'WARNING: ', $t, "\n";
}

function setUpCurl() {
  $ch = curl_init();
  if ($ch === FALSE) throw new Exception("could not initialize curl");
  $options = array(
    CURLOPT_CAINFO => __DIR__.'/../../../../../vendors/curl-ca-bundle/src/ca-bundle.crt',
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

function refreshData($oldData, $xmlStr, $dataset, $indicator) {
  $parser = new OECDDataParser();
  $data = new DC();
  $data->fromJSON($parser->parseXML($xmlStr)->toJSON());
  unset($parser);

  // remove dimensions with only one key
  $xmlKeys = $data->keyMap();
  unset($xmlKeys['YEAR']);
  $filter = [];
  foreach ($xmlKeys as $dim => $keys) {
    if (!in_array($dim, ['COUNTERPART_SECTOR', 'COUNTERPART_AREA', 'ACCOUNTING_ENTRY', 'REF_SECTOR']) && count($keys) == 1) {
      $filter[$dim] = $keys[0];
    }
  }
  $data = $data->filter($filter);
  // exception for Deposits
  $useAssets = ($filter['INSTR_ASSET'] !== 'F2M');

  // record SDW code with data
  $data->updateValues(function ($v, $idx) use($xmlKeys, $dataset) {
    if ($v === NULL) {
      return NULL;
    }
    $code = [$dataset];
    foreach ($xmlKeys as $dim => $keys) {
      if (isset($idx[$dim])) {
        $code[] = $idx[$dim];
      } else {
        $code[] = $keys[0];
      }
    }
    return [implode('.', $code), $v];
  });

  /*
       COUNTERPART_AREA COUNTERPART_SECTOR
             W0               S1              world total
             W1               S1              rest of world total
             W2               S1              EU total (ignored)
             W2               *               EU sectors
  */

  // copy rest of world total to COUNTERPART_SECTOR=ROW
  $data->addKey('COUNTERPART_SECTOR', 'ROW', function($i) use($data) {
    if ($i['COUNTERPART_AREA'] !== 'W2') return NULL;
    $i['COUNTERPART_AREA'] = 'W1';
    $i['COUNTERPART_SECTOR'] = 'S1';
    return $data->atIndex($i);
  });

  // copy world total to COUNTERPART_SECTOR=WLD
  $data->addKey('COUNTERPART_SECTOR', 'WLD', function($i) use($data) {
    if ($i['COUNTERPART_AREA'] !== 'W2') return NULL;
    $i['COUNTERPART_AREA'] = 'W0';
    $i['COUNTERPART_SECTOR'] = 'S1';
    return $data->atIndex($i);
  });

  // copy rest of world total from ACCOUNTING_ENTRY=L to REF_SECTOR=ROW
  $data->addKey('REF_SECTOR', 'ROW', function($i) use($data) {
    if ($i['COUNTERPART_AREA'] !== 'W2') return NULL;
    if ($i['COUNTERPART_SECTOR'] === 'ROW') return NULL;
    if ($i['COUNTERPART_SECTOR'] === 'WLD') {
      $i['REF_SECTOR'] = 'S1';
    } else {
      $i['REF_SECTOR'] = $i['COUNTERPART_SECTOR'];
    }
    $i['COUNTERPART_AREA'] = 'W1';
    $i['COUNTERPART_SECTOR'] = 'S1';
    if ($i['ACCOUNTING_ENTRY'] === 'A') {
      $i['ACCOUNTING_ENTRY'] = 'L';
    } else {
      $i['ACCOUNTING_ENTRY'] = 'A';
    }
    $v = $data->atIndex($i);
    if ($v !== NULL) return $v;
    if ($i['COUNTERPART_SECTOR'] === 'WLD') return $v;
    $i['COUNTERPART_AREA'] = 'W0';
    $w0 = $data->atIndex($i);
    if ($w0 === NULL) return NULL;
    $i['COUNTERPART_AREA'] = 'W2';
    $i['COUNTERPART_SECTOR'] = $i['REF_SECTOR'];
    $i['REF_SECTOR'] = 'S1';
    if ($i['ACCOUNTING_ENTRY'] === 'A') {
      $i['ACCOUNTING_ENTRY'] = 'L';
    } else {
      $i['ACCOUNTING_ENTRY'] = 'A';
    }
    $w2 = $data->atIndex($i);
    if ($w2 === NULL) return NULL;
    return [$w0[0] . " - " . $w2[0], $w0[1] - $w2[1]];
  });

  // copy world total from ACCOUNTING_ENTRY=L to REF_SECTOR=WLD
  $data->addKey('REF_SECTOR', 'WLD', function($i) use($data) {
    if ($i['COUNTERPART_AREA'] !== 'W2') return NULL;
    if ($i['COUNTERPART_SECTOR'] === 'ROW') {
      // no data for ROW/ROW, use EU/ROW for total
      $i['REF_SECTOR'] = 'S1';
      $i['COUNTERPART_AREA'] = 'W1';
      $i['COUNTERPART_SECTOR'] = 'S1';
      return $data->atIndex($i);
    }
    // WLD/WLD data not necessary for flow viz
    if ($i['COUNTERPART_SECTOR'] === 'WLD') return NULL;
    $i['REF_SECTOR'] = $i['COUNTERPART_SECTOR'];
    $i['COUNTERPART_AREA'] = 'W0';
    $i['COUNTERPART_SECTOR'] = 'S1';
    if ($i['ACCOUNTING_ENTRY'] === 'A') {
      $i['ACCOUNTING_ENTRY'] = 'L';
    } else {
      $i['ACCOUNTING_ENTRY'] = 'A';
    }
    return $data->atIndex($i);
  });

  // remove unused or copied data
  $data = $data->filter(['COUNTERPART_AREA' => 'W2', 'ACCOUNTING_ENTRY' => $useAssets ? 'A' : 'L']);
  $k = $data->keyMap();
  $data->setDimension('COUNTERPART_SECTOR', array_values(array_diff($k['COUNTERPART_SECTOR'], ['S1'])));
  $data->setDimension('REF_SECTOR', array_values(array_diff($k['REF_SECTOR'], ['S1'])));

  // set negative values to 0
  $data->updateValues(function ($v) {
    if ($v !== NULL && $v[1] < 0) {
      warn("series has negative values: $v[0] : $v[1]");
      return $v;
//      return [$v[0], 0];
    } else {
      return $v;
    }
  });

  // add NULL data for missing sectors
  $sectors = ['S11', 'S12K', 'S124', 'S128', 'S129', 'S12O', 'S13', 'S1M', 'ROW', 'WLD'];
  $missing = array_diff($sectors, $k['REF_SECTOR']);
  if (count($missing)) {
    $data->addKeys('REF_SECTOR', $missing, function($i) use($missing) {
      return array_fill(0, count($missing), NULL);
    });
  }
  $missing = array_diff($sectors, $k['COUNTERPART_SECTOR']);
  if (count($missing)) {
    $data->addKeys('COUNTERPART_SECTOR', $missing, function($i) use($missing) {
      return array_fill(0, count($missing), NULL);
    });
  }

  // order keys
  $data->setDimension('COUNTERPART_SECTOR', $sectors);
  $data->setDimension('REF_SECTOR', $sectors);

  if ($useAssets) {
    $order = ['REF_SECTOR', 'COUNTERPART_SECTOR', 'YEAR'];
    $headings = ['Holding sector', 'Issuing sector', 'Year - Quarter'];
  } else {
    $order = ['COUNTERPART_SECTOR', 'REF_SECTOR', 'YEAR'];
    $headings = ['Issuing sector', 'Holding sector', 'Year - Quarter'];
  }
  $data->orderDimensions($order);

  // clear specified columns
  if (!empty($indicator['clearColumns'])) {
    $data->updateValues(function ($v, $idx) use($indicator, $order) {
      if ($v !== NULL && in_array($idx[$order[1]], $indicator['clearColumns'])) {
        warn("cleared $v[0]$idx[YEAR]: $v[1]");
        return NULL;
      }
      return $v;
    });
  }

  // remove SDW codes from data
  $codes = [];
  $data->updateValues(function ($v, $idx) use(&$codes) {
    if ($v !== NULL) {
      unset($idx['YEAR']);
      $d = &$codes;
      foreach (array_values($idx) as $key) {
        if (!isset($d[$key])) {
          $d[$key] = [];
        }
        $d = &$d[$key];
      }
      $d = $v[0];
      return $v[1];
    } else {
      return $v;
    }
  });

  $data = $data->toArray();

  // check for missing value in last year
  $bad = 0;
  $tot = 0;
  foreach($data['data'] as $r) {
    foreach ($r as $d) {
      $tot++;
      if ($d[count($d)-1]===null && count(array_filter($d))) {
        $bad++;
      }
    }
  }
  echo "missing new values: $bad/$tot\n";
  $warnings = [];
  if ($bad/$tot >= 0.15) {
    $removed = array_pop($data['keys'][2]);
    $warning = sprintf('%.0f percent new values missing, removing time period %s', $bad*100/$tot, $removed);
    warn($warning);
    $warnings[] = $warning;
    foreach ($data['data'] as &$r) {
      foreach ($r as &$d) {
        array_pop($d);
      }
    }
  }

  $data['sdwCodes'] = $codes;
  $data['dimensions'] = $headings;
  return [$data, $warnings];
}

function updateIndicator($ch, $project, $name, $url, $upto, $force, $indicator) {
  $updated = FALSE;
  $sameData = TRUE;
  $warnings = [];
  $file = __DIR__."/$name.json";
  $data = json_decode(@file_get_contents($file), TRUE);
  $fetchDate = isset($data['fetchDate']) ? $data['fetchDate'] : NULL;
  unset($data['fetchDate']);
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
  echo "indicator $name: ";
  if ($refresh) {
    echo "too old, checking for updates\n";
    flush();
    $now = new DateTime();
    $log = NULL;
    if (defined('LOGFILE_TEMPLATE')) {
      $log = sprintf(LOGFILE_TEMPLATE, $project, $name);
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
          warn("no timestamp in response, using current local time");
          $time = $now;
        }
        $dataset = array_slice(explode('/', explode('?', $url)[0]), -2, 1)[0];
        list($newData, $warnings) = refreshData($data, $res, $dataset, $indicator);
        $newData['url'] = $url;
        $sameData = (json_encode($newData) === json_encode($data));
        $newData['fetchDate'] = gmdate('Y-m-d\TH:i:s', $time->getTimeStamp()) . '.000Z';
        $sameTimestamp = ($newData['fetchDate'] === $fetchDate);
        if ($sameData && !$sameTimestamp) {
          warn("new data matches old data, only updating timestamp");
        }
        if (!$sameData && $sameTimestamp) {
          warn("new data with same timestamp");
          error_log(__FUNCTION__." ($project $name): new data with same timestamp");
        }
        if ($sameData  && $sameTimestamp) {
          echo "same data with same timestamp in SDW\n";
        } else {
          $data = $newData;
          $updated = TRUE;
        }
      }
    } catch (Exception $e) {
      echo "ERROR: caught exception while updating: ", $e->getMessage(), "\n";
      error_log(__FUNCTION__." ($project $name): caught exception while updating: ".$e->getMessage());
    }
  } else {
    echo "up to date\n";
  }
  if ($updated) {
    echo "$project updated\n";
    $tmp = $file . '.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    rename($tmp, $file);
  }
  unset($data);
  flush();
  return [!$sameData, $warnings];
}

function updateProjects($upto, $force) {
  global $ConfigEdit;

  $updateData = [];
  $ch = setUpCurl();
  $series = json_decode(file_get_contents(__DIR__.'/series.json'), TRUE);
  $project = basename(__DIR__);
  echo "updating $project\n";
  foreach ($series['variants'] as $variant => $code) {
    $updated = [];
    foreach ($series['indicators'] as $s) {
      $name = sprintf($s['name'], $variant);
      $url =  sprintf($s['url'], $code);
      list($updates, $warnings) = updateIndicator($ch, $project, $name, $url, $upto, $force, $s);
      if ($updates) {
        $updated[$s['title']] = count($warnings) ? ("\n".implode("\n", $warnings)) : '';
      }
    }
    if (isset($series['total']) && count($updated)) {
      updateTotals($series, $variant);
    }
    foreach ($updated as $title => $msg) {
      $updateData[] = "$title $code$msg";
    }
  }
  curl_close($ch);
  if (count($updateData) && isset($ConfigEdit['updatesEmail'])) {
    sort($updateData);
    $subject = "automatic update: $project";
    $body = "$project updated:\n" . implode("\n", $updateData);
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $headers  = implode("\r\n", array(
      'MIME-Version: 1.0',
      'Content-type: text/plain; charset=utf-8',
      'Content-Transfer-Encoding: base64',
    ));
    mail($ConfigEdit['updatesEmail'], $subject, base64_encode($body), $headers);
  }
}

function updateTotals($series, $variant) {
  unset($data);
  foreach ($series['indicators'] as $s) {
    $name = sprintf($s['name'], $variant);
    $file = __DIR__."/$name.json";
    $tmp = new DC();
    $tmp->loadJSON($file);
    if (!isset($data)) {
      $data = $tmp;
    } else {
      $k = $data->keyMap();
      foreach ($tmp->keyMap() as $dim => $keys) {
        $missing = array_diff($keys, $k[$dim]);
        if (count($missing)) {
          $data->addKeys($dim, $missing, function($i) use($missing) {
            return array_fill(0, count($missing), 0);
          });
        }
      }
      $data->updateValues(function ($v, $idx) use($tmp) {
        if ($idx['Holding sector'] === 'ROW' && $idx['Issuing sector'] === 'ROW') return NULL;
        if ($idx['Holding sector'] === 'WLD' && $idx['Issuing sector'] === 'WLD') return NULL;
        return ($v !== NULL ? $v : 0) + $tmp->atIndex($idx);
      });
      unset($tmp);
    }
  }
  $data = $data->toArray();
  $data['fetchDate'] = gmdate('Y-m-d\TH:i:s') . '.000Z';
  $name = sprintf($series['total'], $variant);
  $file = __DIR__."/$name.json";
  $tmp = $file . '.' . getmypid();
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
  rename($tmp, $file);
}

$upto = (new DateTime('now'))->sub(new DateInterval($interval));
updateProjects($upto, $force);
