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

    protected $filename = null;
    /**
     * @var OutputInterface
     */
    protected $output = null;


    protected function configure()
    {
        $config = Yaml::parse(file_get_contents('app/config/parameters.yml'));

        $this
            ->setName('eurotax:import')
            ->addOption('ftp-host', null, InputOption::VALUE_OPTIONAL, 'ftp host', $config['parameters']['ftpHost'])
            ->addOption('ftp-username', null, InputOption::VALUE_OPTIONAL, 'ftp username', $config['parameters']['ftpUsername'])
            ->addOption('ftp-pass', null, InputOption::VALUE_OPTIONAL, 'ftp pass', $config['parameters']['ftpPass'])
            ->addOption('mysql-database', null, InputOption::VALUE_OPTIONAL, 'mysql database', $config['parameters']['mysqlDatabase'])
            ->addOption('mysql-username', null, InputOption::VALUE_OPTIONAL, 'mysql username', $config['parameters']['mysqlUsername'])
            ->addOption('mysql-pass', null, InputOption::VALUE_OPTIONAL, 'mysql pass', $config['parameters']['mysqlPass'])
            ->addOption('filename', null, InputOption::VALUE_REQUIRED, 'filename', $config['parameters']['filename'])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->filename = sprintf($input->getOption('filename'), date('Ym'), date('Ymd')) . '.zip';
        $this->output = $output;
        $ftpHost = $input->getOption('ftp-host');
        $ftpUserName = $input->getOption('ftp-username');
        $ftpUserPass = $input->getOption('ftp-pass');
        $mysqlDatabaseName = $input->getOption('mysql-database');
        $mysqlUserName = $input->getOption('mysql-username');
        $mysqlPassword = $input->getOption('mysql-pass');
        // fetch the ftp server
        $this->fetchFileFromFtp($ftpHost, $ftpUserName, $ftpUserPass);
        // unzip the result
        $this->extractArchive();
        // import it in database
        $this->importSql($mysqlDatabaseName, $mysqlUserName, $mysqlPassword);

    }

    private function fetchFileFromFtp($ftpServer, $ftpUserName, $ftpUserPass)
    {
        $conn_id = ftp_connect($ftpServer);

        $login_result = ftp_login($conn_id, $ftpUserName, $ftpUserPass);

        if ((!$conn_id) || (!$login_result)) {
            $this->output->writeln($message = "FTP connection failed");
            throw new \Exception($message);
        } else {
            $this->output->writeln("FTP connextion initialized");
        }

        $sourceFile = $this->getFilePath() . $this->filename;

        $destinationFile = $this->getFilePath(true) . $this->filename;
        $this->output->writeln(sprintf("Downloading %s", $destinationFile));
        $upload = ftp_get($conn_id, $destinationFile, $sourceFile, FTP_BINARY);

        if (!$upload) {
            $this->output->writeln($message = sprintf("The file '%s' was not found in the FTP", $sourceFile));
            exit;
        }

        ftp_close($conn_id);
        $this->output->writeln("Download complete");
    }

    private function getFilePath($local = false)
    {
        if ($local) {
            $path = '/tmp/eurotax/';
        } else {
            $path = '/in/';
        }
        return $path;
    }

    private function extractArchive()
    {
        $this->output->writeln('Extract archive');
        $zip = new ZipArchive;
        if ($zip->open($this->getFilePath(true) . $this->filename) === TRUE) {
            $dir = $this->getFilePath(true) . date('Ym') . '/';
            if (is_dir($dir)) {
                throw new \Exception(sprintf('Database for %s seems to already be imported', date('Ym')));
            } else {
                $zip->extractTo($dir);
                $zip->close();
                $this->output->writeln('Extract completed');
            }
        } else {
            $this->output->writeln($message = 'Extract failed');
            throw new \Exception($message);
        }
    }

    private function importSql($mysqlDatabaseName, $mysqlUserName, $mysqlPassword)
    {
        $tmpDatabase = $mysqlDatabaseName . '_tmp';
        exec("mysql -u ".$mysqlUserName." --password='".$mysqlPassword."' -e 'DROP DATABASE IF EXISTS `".$tmpDatabase."`; CREATE DATABASE ".$tmpDatabase."'");
        exec("mysqldump -d -u ".$mysqlUserName." --password='".$mysqlPassword."' ".$mysqlDatabaseName." | mysql -u ".$mysqlUserName." --password='".$mysqlPassword."' ".$tmpDatabase);
        $mysqlImportDir = new DirectoryIterator($this->getFilepath(true) . date('Ym'));
        foreach ($mysqlImportDir as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->getFilename() === '.DS_Store') {
                continue;
            }
            $tableName = $fileInfo->getBaseName('.txt');
            $file = $fileInfo->getPathname();

            $tableExist = exec(sprintf('mysql -N -s -u%s -p%s -e "select count(*) from information_schema.tables where table_schema=\'%s\' and table_name=\'%s\';"',
                $mysqlUserName,
                $mysqlPassword,
                $tmpDatabase,
                $tableName
            ));
            if ($tableExist) {
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

        //send mail
        exec("curl -s --user 'api:key-6iurj37nbbqdl8wibdrqthuhp29941p4' https://api.mailgun.net/v3/vpauto.fr/messages -F from='Serveur VPAUTO <postmaster@vpauto.fr>' -F to='vpauto@appventus.com; ehamelin@vpauto.fr' -F subject='EUROTAX IMPORT OK' -F text='need manual database rename'");


    }
}
