<?php

$oneOffSettingsTabs = array (
  1449847839830 => 
  array (
    'altTemplate' => 
    array (
      0 => 1,
      1 => 6,
      2 => 7,
    ),
  ),
  1449849086859 => 
  array (
    'altTemplate' => 
    array (
      0 => 1,
      1 => 6,
      2 => 7,
    ),
  ),
  't1571672238268' => 
  array (
    'altTemplate' => 
    array (
      0 => 1,
      1 => 6,
      2 => 7,
    ),
  ),
  't1571672318658' => 
  array (
    'altTemplate' => 
    array (
      0 => 1,
      1 => 6,
      2 => 7,
    ),
  ),
  1452075533276 => 
  array (
    'altTemplate' => 
    array (
      0 => 1,
      1 => 6,
      2 => 7,
    ),
  ),
  1452075926783 => 
  array (
    'altTemplate' => 
    array (
      0 => 1,
      1 => 6,
      2 => 7,
    ),
  ),
);

$oneOffSettingsCharts = array (
  1449848157050 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1449848222069 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1449848859297 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1449848889065 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1449848899771 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452074667935 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075262074 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075302996 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075316620 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  'c1571672246073' => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  'c1571672264338' => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  'c1571672269375' => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  'c1571672321160' => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  'c1571672322842' => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  'c1571672323643' => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075551630 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075562630 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075568709 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075952750 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075961846 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
  1452075967901 => 
  array (
    'options' => 
    array (
      'mapRegion' => 'EUR',
    ),
  ),
);

if (defined('CODE_PREVIEW') && strpos($GLOBALS['path'], CODE_PREVIEW)) {
  array_walk($oneOffSettingsTabs, function (&$t) { $t['mapColor'] = 'RdBuGn8'; });
  array_walk($oneOffSettingsCharts, function (&$t) { $t['options']['mapDivergent'] = true; });
}
