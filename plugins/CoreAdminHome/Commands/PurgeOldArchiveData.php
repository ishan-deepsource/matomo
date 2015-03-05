<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CoreAdminHome\Commands;

use Piwik\Archive;
use Piwik\Archive\Purger;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Date;
use Piwik\Db;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Timer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command that allows users to force purge old or invalid archive data. In the event of a failure
 * in the archive purging scheduled task, this command can be used to manually delete old/invalid archives.
 * TODO: command tests
 */
class PurgeOldArchiveData extends ConsoleCommand
{
    const ALL_DATES_STRING = 'all';

    /**
     * @var Purger
     */
    private $archivePurger;

    public function __construct(Purger $archivePurger = null)
    {
        parent::__construct();

        $this->archivePurger = $archivePurger ?: new Purger();
    }

    protected function configure()
    {
        $this->setName('core:purge-old-archive-data');
        $this->setDescription('Purges old and invalid archive data from archive tables.');
        $this->addArgument("dates", InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            "The months of the archive tables to purge data from. By default, only deletes from the current month. Use '" . self::ALL_DATES_STRING. "' for all dates.",
            array(Date::today()->toString()));
        $this->addOption('exclude-outdated', null, InputOption::VALUE_NONE, "Do not purge outdated archive data.");
        $this->addOption('exclude-invalidated', null, InputOption::VALUE_NONE, "Do not purge invalidated archive data.");
        $this->addOption('skip-optimize-tables', null, InputOption::VALUE_NONE, "Do not run OPTIMIZE TABLES query on affected archive tables.");
        $this->setHelp("By default old and invalidated archives are purged. Custom ranges are also purged with outdated archives.\n\n"
                     . "Note: archive purging is done during scheduled task execution, so under normal circumstances, you should not need to "
                     . "run this command manually.");

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $archivePurger = $this->archivePurger;

        $dates = $this->getDatesToPurgeFor($input);

        $excludeOutdated = $input->getOption('exclude-outdated');
        if ($excludeOutdated) {
            $output->writeln("Skipping purge outdated archive data.");
        } else {
            foreach ($dates as $date) {
                $message = sprintf("Purging outdated archives for %s...", $date->toString('Y_m'));
                $this->performTimedPurging($output, $message, function () use ($date, $archivePurger) {
                    $archivePurger->purgeOutdatedArchives($date);
                });
            }
        }

        $excludeInvalidated = $input->getOption('exclude-invalidated');
        if ($excludeInvalidated) {
            $output->writeln("Skipping purge invalidated archive data.");
        } else {
            foreach ($dates as $date) {
                $message = sprintf("Purging invalidated archives for %s...", $date->toString('Y_m'));
                $this->performTimedPurging($output, $message, function () use ($archivePurger, $date) {
                    $archivePurger->purgeInvalidatedArchivesFrom($date);
                });
            }
        }

        $skipOptimizeTables = $input->getOption('skip-optimize-tables');
        if ($skipOptimizeTables) {
            $output->writeln("Skipping OPTIMIZE TABLES.");
        } else {
            $this->optimizeArchiveTables($output, $dates);
        }
    }

    /**
     * @param InputInterface $input
     * @return Date[]
     */
    private function getDatesToPurgeFor(InputInterface $input)
    {
        $dates = array();

        $dateSpecifier = $input->getArgument('dates');
        if (count($dateSpecifier) === 1
            && reset($dateSpecifier) == self::ALL_DATES_STRING
        ) {
            foreach (ArchiveTableCreator::getTablesArchivesInstalled() as $table) {
                $tableDate = ArchiveTableCreator::getDateFromTableName($table);

                list($year, $month) = explode('_', $tableDate);

                $dates[] = Date::factory($year . '-' . $month . '-' . '01');
            }
        } else {
            foreach ($dateSpecifier as $date) {
                $dates[] = Date::factory($date);
            }
        }

        return $dates;
    }

    private function performTimedPurging(OutputInterface $output, $startMessage, $callback)
    {
        $timer = new Timer();

        $output->write($startMessage);

        $callback();

        $output->writeln("Done. <comment>[" . $timer->__toString() . "]</comment>");
    }

    /**
     * @param Date[] $dates
     */
    private function optimizeArchiveTables(OutputInterface $output, $dates)
    {
        $output->writeln("Optimizing archive tables...");

        foreach ($dates as $date) {
            $numericTable = ArchiveTableCreator::getNumericTable($date);
            $this->performTimedPurging($output, "Optimizing table $numericTable...", function () use ($numericTable) {
                Db::optimizeTables($numericTable);
            });

            $blobTable = ArchiveTableCreator::getBlobTable($date);
            $this->performTimedPurging($output, "Optimizing table $blobTable...", function () use ($blobTable) {
                Db::optimizeTables($blobTable);
            });
        }
    }
}
