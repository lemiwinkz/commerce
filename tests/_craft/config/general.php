<?php

/**
 * General Configuration
 *
 * All of your system's general configuration settings go in here.
 * You can see a list of the default settings in craft/app/config/defaults/general.php
 */

return [
    'useProjectConfigFile' => true,
    'devMode'=>true,
    'siteUrl' => 'https://test.craftcms.dev/',
    'slugWordSeparator' => '--',
    'allowUppercaseInSlug' => true,
    'securityKey' => getenv('SECURITY_KEY')
];
