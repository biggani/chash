<?php

namespace Chash\Command\Files;

use Chash\Command\Database\CommonChamiloDatabaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ShowDiskUsageCommand
 * Show the total disk usage per course compared to the maximum space allowed for the corresponding courses
 * @package Chash\Command\Files
 */
class ShowDiskUsageCommand extends CommonChamiloDatabaseCommand
{
    /**
     *
     */
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('files:show_disk_usage')
            ->setAliases(array('fsdu'))
            ->setDescription('Shows the disk usage vs allowed space, per course')
            ->addOption(
                'multi-url',
                null,
                InputOption::VALUE_NONE,
                'Show the results split by url, if using the multi-url feature, using an unprecise "best-guess" process considering all session-specific material to be part of the same root course'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return bool|int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        $this->writeCommandHeader($output, 'Checking courses dir...');

        $dialog = $this->getHelperSet()->get('dialog');

        if (!$dialog->askConfirmation(
            $output,
            '<question>This operation can take several hours on large volumes. Continue? (y/N)</question>',
            false
        )
        ) {
            return;
        }

        // Get database and path information
        $coursesPath = $this->getConfigurationHelper()->getSysPath();
        $this->getConfigurationHelper()->getConnection();
        $_configuration = $this->getConfigurationHelper()->getConfiguration();
        // Check whether we want to use multi-url
        $portals = array(1 => 'http://localhost/');
        $multi = $input->getOption('multi-url'); //1 if the option was set
        if ($multi) {
            $output->writeln('Using multi-url mode');
            $urlTable = $_configuration['main_database'].'.access_url';
            $sql = "SELECT id, url FROM $urlTable ORDER BY url";
            $res = mysql_query($sql);
            if ($res != false) {
                while ($row = mysql_fetch_assoc($res)) {
                    $portals[$row['id']] = $row['url'];
                }
            }
        }

        $courseTable = $_configuration['main_database'].'.course';
        $courseTableUrl = $_configuration['main_database'].'.access_url_rel_course';
        $globalCourses = array();
        $sql = "SELECT c.id as cid, c.code as ccode, c.directory as cdir, c.disk_quota as cquota
                FROM $courseTable c";
        $res = mysql_query($sql);
        if ($res && mysql_num_rows($res) > 0) {
            while ($row = mysql_fetch_assoc($res)) {
                $globalCourses[$row['cdir']] = array('code' => $row['ccode'], 'quota' => $row['cquota']);
            }
        }

        $totalSize = 0; //size for all portals combined
        $finalList = array();
        $orphanList = array();
        $dirs = $this->getConfigurationHelper()->getDataFolders(1);
        // browse all the portals
        foreach ($portals as $portalId => $portalName) {
            if (empty($portalId)) { continue; }
            // for CSV output, there must be 4 ";" on each line
            $sql = "SELECT u.access_url_id as uid, c.id as cid, c.code as ccode, c.directory as cdir, c.disk_quota as cquota
                FROM $courseTable c JOIN $courseTableUrl u ON u.course_code = c.code
                WHERE u.access_url_id = $portalId";
            $res = mysql_query($sql);
            $localCourses = array();
            if ($res && mysql_num_rows($res) > 0) {
                while ($row = mysql_fetch_assoc($res)) {
                    if (!empty($row['cdir'])) {
                        $localCourses[$row['cdir']] = array('code' => $row['ccode'], 'quota' => $row['cquota']);
                    }
                }
            }
            $localSize = 0;
            if (count($dirs) > 0) {
                $output->writeln('=== Code;Size(KB);Quota(KB);UsedRatio');
                foreach ($dirs as $dir) {
                    $file = $dir->getFileName();
                    if (isset($localCourses[$file]['code']) && isset($globalCourses[$file]['code']) && isset($finalList[$globalCourses[$file]['code']])) {
                        // if this course has already been analysed, recover existing information
                        $size = $finalList[$globalCourses[$file]['code']]['size'];
                        $output->writeln($globalCourses[$file]['code'].
                            ';'.$size.
                            ';'.$finalList[$globalCourses[$file]['code']]['quota'].
                            ';'.$finalList[$globalCourses[$file]['code']]['rate']);
                        $localSize += $size;
                    } else {
                        $res = exec('du -s '.$dir->getRealPath());
                        $res = preg_split('/\s/',$res);
                        $size = $res[0];

                        if (isset($localCourses[$file]['code'])) {
                            $localSize += $size; //always add size to local portal (but only add to total size if new)
                            $code = $localCourses[$file]['code'];
                            $quota = round($localCourses[$file]['quota']/1024, 0);
                            $rate = '-';
                            if ($quota > 0) {
                                $rate = round(($size/$quota)*100, 0);
                            }
                            $finalList[$code] = array(
                                'code'  => $code,
                                'dir'   => $file,
                                'size'  => $size,
                                'quota' => $quota,
                                'rate'  => $rate,
                            );
                            $totalSize += $size; //only add to total if new course

                            $output->writeln($code.';'.$size.';'.$finalList[$code]['quota'].';'.$rate);
                        } elseif (!isset($globalCourses[$file]['code']) && !isset($orphanList[$file])) {
                            // only add to orphans if not in global list from db
                            $orphanList[$file] = array('size' => $size);
                        }
                    }
                }
            }
            $output->writeln('--- Subtotal;'.$portalName.' ('.$portalId.');'.$localSize.';;');
        }
        if (count($orphanList) > 0) {
            $output->writeln('=== Code;Size(KB);Quota(KB);UsedRatio');
            foreach($orphanList as $key => $orphan) {
                $output->writeln('ORPHAN-DIR:'.$key.';'.$size.';;;');
                $totalSize += $size;
            }
        }
        $output->writeln('### Total size: '.$totalSize);
    }
}
