#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use AppBundle\Command\EurotaxImporterCommand;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add($command = new EurotaxImporterCommand());
$application->setDefaultCommand($command->getName());
$application->run();
