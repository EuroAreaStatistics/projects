<?php

$shareLanguagesProjects = array(
  'langtheme' => array('bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'hr', 'hu', 'it', 'lt', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'sk', 'sl', 'sv'),
);
$shareLanguagesProjects[''] = $shareLanguagesProjects['langtheme'];

$editProjects = array();

$projectsNotes = array();
$ConfigCenters = array();

$ConfigEdit = array(
//  'email'         => 'user@exmaple.com', // email for edit notifications
//  'updatesEmail'  => 'user@exmaple.com', // email for data update notifications
  'admin'         => 'ECBstage',
  'languages'     => $shareLanguagesProjects['langtheme'],
  'allowedTags'   => array(
    'langtheme' => '<strong><em><br><a>',
    'langmain' => '<strong><em><br><a>',
    'lang' => '<strong><em><br><a>',
    'wizard' => '<p><h4><strong><em><a><img><ul><li>',
  ),
  'projects' => array(),
);
