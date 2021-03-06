<?php

use Scalr\Acl\Acl;
use Scalr\DataType\AggregationCollection;
use Scalr\Stats\CostAnalytics\Entity\UsageTypeEntity;
use Scalr\Stats\CostAnalytics\Forecast;
use Scalr\Stats\CostAnalytics\Iterator\ChartPeriodIterator;
use Scalr\Stats\CostAnalytics\Entity\ProjectEntity;

class Scalr_UI_Controller_Account2_Analytics extends Scalr_UI_Controller
{
    use Forecast;

    public function hasAccess()
    {
        return parent::hasAccess() && $this->request->isAllowed(Acl::RESOURCE_ANALYTICS_ACCOUNT);
    }

    /**
     * Gets events list
     *
     * @param string $mode      Chart mode
     * @param string $date      The requested date time
     * @param string $start     Start date of the current period
     * @param string $end       optional End date of the period
     * @param string $envId     optional Environment id
     * @param string $projectId optional Project id
     * @throws InvalidArgumentException
     */
    public function xGetTimelineEventsAction($mode, $date, $start, $end = null, $envId = null, $projectId = null)
    {
        if (!preg_match('/^[\d]{4}-[\d]{2}-[\d]{2} [\d]{2}:00$/', $date)) {
            throw new InvalidArgumentException(sprintf("Invalid date:%s. 'YYYY-MM-DD HH:00' is expected.", strip_tags($date)));
        }

        if (!empty($envId)) {
            $env = Scalr_Environment::init()->loadById($envId);

            if ($env->clientId !== $this->user->getAccountId()) {
                throw new Scalr_Exception_InsufficientPermissions();
            }
        }

        $analytics = $this->getContainer()->analytics;

        $iterator = ChartPeriodIterator::create($mode, $start, ($end ?: null), 'UTC');

        $pointPosition = $iterator->searchPoint($date);

        if ($pointPosition !== false) {
            $chartPoint = $iterator->current();
            $startDate = $chartPoint->dt;

            if ($chartPoint->isLastPoint) {
                $endDate = $chartPoint->end;
            } else {
                $iterator->next();
                $endDate = $iterator->current()->dt;
                $endDate->modify("-1 second");
            }
        }

        if (!isset($startDate)) {
            throw new OutOfBoundsException(sprintf("Date %s is inconsistent with the interval object", $date));
        }

        $entities = $analytics->events->get($startDate, $endDate, ['envId' => $envId, 'accountId' => $this->user->getAccountId(), 'projectId' => $projectId]);

        $data = [];

        foreach ($entities as $entity) {
            $data[] = [
                'dtime'       => $entity->dtime->format('Y-m-d H:i:s'),
                'description' => $entity->description,
                'type'        => $entity->eventType
            ];
        }

        $this->response->data(['data' => $data]);
    }

    /**
     * xGetPeriodLogAction
     *
     * @param   string    $mode      The mode (week, month, quarter, year)
     * @param   string    $startDate The start date of the period in UTC ('Y-m-d')
     * @param   string    $endDate   The end date of the period in UTC ('Y-m-d')
     * @param   string    $type      Type of the data gathered in log file
     * @param   int       $envId     optional The identifier of the environment
     * @param   string    $projectId optional The identifier of the project
     */
    public function xGetPeriodCsvAction($mode, $startDate, $endDate, $type, $envId = null, $projectId = null)
    {
        if ($type !== 'clouds') {
            $name = 'Farm';
        } else {
            $name = 'Cloud';
        }

        if (!empty($envId)) {
            $env = Scalr_Environment::init()->loadById($envId);

            if ($env->clientId !== $this->user->getAccountId()) {
                throw new Scalr_Exception_InsufficientPermissions();
            }
            $data = $this->getContainer()->analytics->usage->getEnvironmentPeriodData($env, $mode, $startDate, $endDate);
            $fileName = 'Environment' . $env->id;
        } else if (!empty($projectId)) {
            $filter = ['accountId' => $this->user->getAccountId()];
            $data = $this->getContainer()->analytics->usage->getProjectPeriodData($projectId, $mode, $startDate, $endDate, $filter);
            $entity = ProjectEntity::findPk($projectId);

            if ($type !== 'clouds') {
                $extraFields = 'Project name;Billing code;Lead email address;';
            }

            $fileName = 'Project' . '.' . $entity->name . '.' . $entity->getProperty('billing.code');
        } else {
            throw new \InvalidArgumentException(sprintf(
                "Method %s requires both envId or projectId to be specified.",
                __METHOD__
            ));
        }

        if (!empty($entity)) {
            $extraData = [$entity->name, $entity->getProperty('billing.code'), $entity->getProperty('lead.email')];
        }

        $head[] = $name;
        $end[] = "Total spent";

        if (isset($extraFields)) {
            $head = array_merge($head, $extraData);
            $end = array_merge($end, $extraData);
        }

        $totals = 0;

        foreach ($data['timeline'] as $timeline) {
            $totals += $timeline['cost'];
            $head[] = $timeline['label'];
            $end[] = $timeline['cost'];
        }

        $head[] = 'Total';
        $end[] = $totals;

        $temp = tmpfile();

        fputcsv($temp, $head);

        foreach ($data[$type] as $item) {
            $row = [];

            $row[] = $item['name'];

            $dataCost = [];

            foreach ($data['timeline'] as $key => $value) {
                $dataCost[] = (isset($item['data'][$key]['cost']) ? $item['data'][$key]['cost'] : 0);
            }

            $itemTotal = array_sum($dataCost);

            if (isset($extraFields)) {
                $row = array_merge($row, $extraData);
            }

            $row = array_merge($row, $dataCost);
            $row[] = $itemTotal;

            fputcsv($temp, $row);
        }

        fputcsv($temp, $end);

        $metadata = stream_get_meta_data($temp);

        $fileName = $fileName . '.' . $type . '.' . Scalr_Util_DateTime::convertTz(time(), 'M_j_Y_H:i:s');

        $bad = array_merge(
            array_map('chr', range(0,31)),
            ["<", ">", ":", '"', "/", "\\", "|", "?", "*"]);

        $fileName = str_replace($bad, "", $fileName);

        $this->response->setHeader('Content-Encoding', 'utf-8');
        $this->response->setHeader('Content-Type', 'text/csv', true);
        $this->response->setHeader('Expires', 'Mon, 10 Jan 1997 08:00:00 GMT');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Cache-Control', 'post-check=0, pre-check=0');
        $this->response->setHeader('Content-Disposition', 'attachment; filename=' . $fileName . ".csv");
        $this->response->setResponse(file_get_contents($metadata['uri']));
        fclose($temp);
    }

    /**
     * Gets the detailed usage for the specified Farm and point on the chart
     *
     * @param   string      $mode         The mode (chart)
     * @param   string      $date         The UTC date within period ('Y-m-d H:00')
     * @param   string      $start        The start date of the period in UTC ('Y-m-d')
     * @param   string      $end          The end date of the period in UTC ('Y-m-d')
     * @param   strign      $projectId    optional Identifier of the Project
     * @param   int         $envId        optional Identifier of the Environment
     * @param   string      $farmId       optional Identifier of the Farm
     * @param   int         $farmRoleId   optional Identifier of the FarmRole
     * @throws Scalr_Exception_InsufficientPermissions
     */
    public function xGetUsageItemsAction($mode, $date, $start, $end, $projectId = null, $envId = null, $farmId = null, $farmRoleId = null)
    {
        if (!is_numeric($farmId)) {
            $farmId = @json_decode($farmId, true);
        } elseif (empty($farmId)) {
            $farmId = null;
        } else {
            $farmId = intval($farmId);
        }

        if (!empty($farmId) && is_array($farmId)) {
            $farmId = ['$nin' => $farmId];
        }

        $data = $this->getContainer()->analytics->usage->getFarmPointData(
            $this->user->getAccountId(), $projectId, $envId, $farmId, $farmRoleId, $mode, $date, $start, $end
        );

        $this->response->data(['distributionTypes' => $data]);
    }

}
