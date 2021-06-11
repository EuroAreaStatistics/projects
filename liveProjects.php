<?php

$projectsWizard = json_decode(file_get_contents(__DIR__.'/liveWizardProjects.json'), TRUE);

$projectsConstructionWizard = array();
$projectsWizardApps = array();

$projectsClassic = array(
  'banks-corner',
  'banks-corner-mir',
  'banks-corner-bsi',
  'banks-corner-sec',
  'banks-corner-sbs',
  'issuance-of-debt-securities-by-euro-area-residents',
  'euro-area-and-national-breakdowns-of-banks-loans-and-deposit-interest-rates',
  'current-account-balance-of-the-euro-area',
  'positive-trend-in-euro-area-bank-loans-to-corporates-continues',
  'investment-funds-in-the-euro-area',
  'corporates-debt-ratio-in-the-euro-area',
  'developments-in-the-market-value-of-listed-shares-vary-across-the-euro-area',
  'crypto-assets',
  'payment-statistics',
  'growth-rates-in-housing-loans-are-steadily-increasing-in-the-euro-area',
);
if (file_exists(__DIR__.'/../../classic/02projects/ecb/wizard-edit-repo/wizardProjects/items-insights.json')) {
  $projectsClassic = array_values(array_unique(array_merge($projectsClassic, array_map(function ($v) { return $v['id']; }, json_decode(file_get_contents(__DIR__.'/../../classic/02projects/ecb/wizard-edit-repo/wizardProjects/items-insights.json'), TRUE)))));
}

foreach ($projectsClassic as $classicProject) {
  $urls['/'.$classicProject] = array('redirect' => '/classic/'.$classicProject);
}

$urls['/the-value-of-households-holdings-of-investment-fund-shares-reaches-new-highs'] = ['redirect' => '/classic/investment-funds-in-the-euro-area'];
$urls['/classic/the-value-of-households-holdings-of-investment-fund-shares-reaches-new-highs'] = ['redirect' => '/classic/investment-funds-in-the-euro-area'];
