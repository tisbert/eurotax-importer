#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use AppBundle\Command\EurotaxImporterCommand;
use AppBundle\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;

$application = new Application();
$application->run();
