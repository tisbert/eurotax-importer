<?php
namespace AppBundle\Command;

use DirectoryIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class EurotaxImporterCommand extends Command
{

    const ERROR = 'error';
    const SUCCESS = 'success';

    /**
     * @var OutputInterface
     */
    protected $output = null;


    protected function configure()
    {
        $config = Yaml::parse(file_get_contents(dirname(__FILE__) . '/../../../app/config/parameters.yml'));

        $this
            ->setName('eurotax:import')
            ->addOption('ftp-host', null, InputOption::VALUE_OPTIONAL, 'ftp host', $config['parameters']['ftpHost'])
            ->addOption('mysql-database', null, InputOption::VALUE_OPTIONAL, 'mysql database', $config['parameters']['mysqlDatabase'])
            ->addOption('mysql-username', null, InputOption::VALUE_OPTIONAL, 'mysql username', $config['parameters']['mysqlUsername'])
            ->addOption('mysql-pass', null, InputOption::VALUE_OPTIONAL, 'mysql pass', $config['parameters']['mysqlPass'])
            ->addOption('ssh-user', null, InputOption::VALUE_OPTIONAL, 'ssh user', $config['parameters']['sshUser'])
            ->addOption('ssh-key', null, InputOption::VALUE_OPTIONAL, 'path to the ssh key', $config['parameters']['sshKey'])
            ->addOption('filenamePattern', null, InputOption::VALUE_REQUIRED, 'filename', $config['parameters']['filenamePattern'])
            ->addOption('filename', null, InputOption::VALUE_OPTIONAL, 'filename', null)
            ->addOption('warn-emails', null, InputOption::VALUE_OPTIONAL, 'emails of peaple to warn (separated by ";")', $config['parameters']['warnEmails'])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {

            if ($input->getOption('filename')) {
                $filename = $input->getOption('filename');
            } else {
                $filename = sprintf($input->getOption('filenamePattern'), date('Ym'), date('Ymd')) . '.zip';
            }
            $this->output = $output;
            $this->fetchFileFromFtp(
                $input->getOption('ftp-host'),
                $input->getOption('ssh-user'),
                $input->getOption('ssh-key'),
                $filename
            );
            $this->extractArchive($filename);
            $this->importSql(
                $input->getOption('mysql-database'),
                $input->getOption('mysql-username'),
                $input->getOption('mysql-pass'),
                $filename
            );
        } catch (\Exception $e) {
            $this->warn($input->getOption('warn-emails'), self::ERROR);
            throw $e;
        }
        $this->warn($input->getOption('warn-emails'), self::SUCCESS);
    }

    /**
     * fetch the ftp server
     *
     * @param $ftpServer
     * @param $sshUser
     * @param $sshKey
     *
     * @throws \Exception
     */
    private function fetchFileFromFtp($ftpServer, $sshUser, $sshKey, $filename)
    {
        $conn_id = ssh2_connect($ftpServer);

        // login on the sftp server
        $login_result = ssh2_auth_pubkey_file(
            $conn_id,
            $sshUser,
            sprintf('%s.pub', $sshKey),
            $sshKey
        );

        if ((!$conn_id) || (!$login_result)) {
            $this->output->writeln($message = "FTP connection failed");
            throw new \Exception($message);
        } else {
            $this->output->writeln("FTP connextion initialized");
        }

        $sourceFile = $this->getFilePath() . $filename;
        $destinationFile = $this->getFilePath(true) . $filename;

        if (file_exists($destinationFile)) {
            $this->output->writeln("Archive already downloaded");
            return;
        }
        // open remote import file
        $sftp = ssh2_sftp($conn_id);
        $importFile = fopen("ssh2.sftp://$sftp/.$sourceFile", 'r');

        //create tmp dir if not exists
        if(!@mkdir(sys_get_temp_dir().'/eurotax') && !is_dir(sys_get_temp_dir().'/eurotax')) {
            throw new \Exception(sprintf('Could not create the %s directory', sys_get_temp_dir().'/eurotax'));
        }
        //create local import file
        $destinationFileStream = fopen($destinationFile, "w");
        $this->output->writeln(sprintf("Downloading %s into %s", $sourceFile,$destinationFile));
        // download remote import file
        if (!file_put_contents($destinationFile, $importFile)) {
            $this->output->writeln($message = sprintf("The file '%s' was not found in the FTP", $sourceFile));
            exit;
        }

        fclose($importFile);
        fclose($destinationFileStream);
        $this->output->writeln("Download complete");
    }


    /**
     * unzip the result
     *
     * @throws \Exception
     */
    private function extractArchive($filename)
    {
        $this->output->writeln('Extract archive');
        $zip = new ZipArchive;
        if ($zip->open($this->getFilePath(true) . $filename) === TRUE) {
            $dir = $this->getFilePath(true) . str_replace('.zip', '', $filename) . '/';
            $zip->extractTo($dir);
            $zip->close();
            $this->output->writeln('Extract completed');

        } else {
            $this->output->writeln($message = 'Extract failed: unable to open '.$this->getFilePath(true) . $filename);
            throw new \Exception($message);
        }
    }

    /**
     * import extracted data in the database
     *
     * @param $mysqlDatabaseName
     * @param $mysqlUserName
     * @param $mysqlPassword
     *
     * @throws \Exception
     */
    private function importSql($mysqlDatabaseName, $mysqlUserName, $mysqlPassword, $filename)
    {
        $tmpDatabase = $mysqlDatabaseName . '_tmp';
        // create temporary database
        exec("mysql -u ".$mysqlUserName." --password='".$mysqlPassword."' -e 'DROP DATABASE IF EXISTS `".$tmpDatabase."`; CREATE DATABASE ".$tmpDatabase."'");
        // copy the database structure into the tmp one
        exec("mysqldump -d -u ".$mysqlUserName." --password='".$mysqlPassword."' ".$mysqlDatabaseName." | mysql -u ".$mysqlUserName." --password='".$mysqlPassword."' ".$tmpDatabase);
        $mysqlImportDir = new DirectoryIterator($this->getFilepath(true) . str_replace('.zip', '', $filename));
        //iterate over each imported data files
        foreach ($mysqlImportDir as $fileInfo) {
            if ($fileInfo->isDot() || strpos($fileInfo->getFilename(), '.') === 0) {
                continue;
            }
            $tableName = $fileInfo->getBaseName('.txt');
            $file = $fileInfo->getPathname();

            // If the table exists
            if (exec(sprintf('mysql -N -s -u%s -p%s -e "select count(*) from information_schema.tables where table_schema=\'%s\' and table_name=\'%s\';"',
                $mysqlUserName,
                $mysqlPassword,
                $tmpDatabase,
                $tableName
            ))) {
                // load data into the table
                $command = sprintf('mysql --enable-local-infile -u%s -p%s %s -e "USE %s; TRUNCATE TABLE %s; LOAD DATA LOCAL INFILE \'%s\' INTO TABLE %s CHARACTER SET \'latin1\' FIELDS TERMINATED BY \'\t\' LINES TERMINATED BY \'\n\';"',
                    $mysqlUserName,
                    $mysqlPassword,
                    $tmpDatabase,
                    $tmpDatabase,
                    $tableName,
                    $file,
                    $tableName
                );
                $output = [];
                exec($command, $output, $returnCode);
                if ($returnCode > 0) {
                    $this->output->writeln($message = 'There was an error during import of the "' . $tableName. '" table.');
                    throw new \Exception($message);
                } else {
                    $this->output->writeln('The table "' . $tableName. '" was successfully imported');
                }
            } else {
                $this->output->writeln(sprintf('The table %s does not exists', $tableName));
            }
        }

        $this->output->writeln('Database was successfully imported');
    }

    /**
     * Create remote or local file path
     *
     * @param bool $local
     *
     * @return string
     */
    private function getFilePath($local = false)
    {
        if ($local) {
            $path = sys_get_temp_dir().'/eurotax/';
        } else {
            $path = '/in/';
        }
        return $path;
    }

    /**
     * Warn by email that import finished
     *
     * @param $emails
     */
    private function warn($emails, $type)
    {
        switch ($type) {
            case self::ERROR:
                $subject = 'EUROTAX IMPORT OK';
                $text = 'need manual database rename';
                break;
            case self::SUCCESS:
                $subject = 'EUROTAX IMPORT FAILED';
                $text = 'An error occured in eurotax import';
                break;
        }
        exec("curl -s --user 'api:key-6iurj37nbbqdl8wibdrqthuhp29941p4' https://api.mailgun.net/v3/vpauto.fr/messages -F from='Serveur VPAUTO <postmaster@vpauto.fr>' -F to='$emails' -F subject='$subject' -F text='$text'");

    }
}
