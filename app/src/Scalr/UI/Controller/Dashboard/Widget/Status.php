<?php

use Scalr\Modules\Platforms\Ec2\Ec2PlatformModule;
use Scalr\DataType\AwsStatus\Endpoint;
use Scalr\DataType\AwsStatus\GovEndpoint;

class Scalr_UI_Controller_Dashboard_Widget_Status extends Scalr_UI_Controller_Dashboard_Widget
{

    public function getDefinition()
    {
        return ['type' => 'nonlocal'];
    }

    public function getContent($params = array())
    {
        /* @var $endpoint Endpoint */
        $endpoint =
            $this->getEnvironment()
                 ->getPlatformConfigValue(Ec2PlatformModule::ACCOUNT_TYPE) == Ec2PlatformModule::ACCOUNT_TYPE_GOV_CLOUD
                ? new GovEndpoint()
                : new Endpoint();

        $data = [];
        $services = [
            'Amazon Elastic Compute Cloud',
            'Amazon Relational Database Service',
            'Amazon Simple Storage Service'
        ];
        $awsCachePath = CACHEPATH . "/{$endpoint->cacheFile}";
        if (empty($params['locations'])) {
            $neededLocations = $this->getUsedLocations();
            $params['locations'] = $neededLocations;
        } else {
            $neededLocations = $params['locations'];
        }

        if (file_exists($awsCachePath) && (time() - filemtime($awsCachePath) < 3600)) {
            clearstatcache();
            $time = filemtime($awsCachePath);
            $data = (array) json_decode(file_get_contents($awsCachePath));
        } else {
            $html = @file_get_contents($endpoint->statUrl);
            if ($html) {
                $dom = new DOMDocument();
                $dom->validateOnParse = false;
                @$dom->loadHTML($html);
                $dom->preserveWhiteSpace = false;

                foreach ($endpoint->compliance as $compKey => $compValue) {
                    $div = $dom->getElementById($compValue['name']);
                    $tables = $div->getElementsByTagName('table');
                    $rows = $tables->item(0)->getElementsByTagName('tr');

                    foreach ($rows as $row) {
                        $cols = $row->getElementsByTagName('td');

                        if($cols->length == 0) {
                            continue;
                        }

                        if (preg_match('/(.*)(' . implode('|', $services) . ')(.*)/', $cols->item(1)->nodeValue)) {
                            $regionFilter = $compValue['filter'];
                            if (is_array($compValue['filter'])) {
                                $regionFilter = implode('|', $compValue['filter']);
                            }
                            if (preg_match('/(.*)(' . $regionFilter . ')(.*)/', $cols->item(1)->nodeValue)) {
                                $img = '';
                                $message = '';
                                if ($cols->item(0)
                                         ->getElementsByTagName('img')
                                         ->item(0)
                                         ->getAttribute('src') == 'images/status0.gif'
                                ) {
                                    $img = 'normal.png';
                                } else {
                                    $img = 'disruption.png';
                                    $message = $cols->item(2)->nodeValue;
                                }
                                $data[$compKey][substr(
                                    str_replace($services, array('EC2', 'RDS', 'S3'), $cols->item(1)->nodeValue), 0,
                                    strpos(str_replace($services, array('EC2', 'RDS', 'S3'), $cols->item(1)->nodeValue),' (')
                                )] = array(
                                    'img'     => $img,
                                    'status'  => $cols->item(2)->nodeValue,
                                    'message' => $message
                                );
                                $data[$compKey]['locations'] = $compKey;
                            }
                        }
                    }

                }

                file_put_contents($awsCachePath, json_encode($data));
            } else {
                return [];
            }
        }

        $retval = array('locations' => json_encode($neededLocations));
        foreach ($neededLocations as $value) {
            $retval['data'][] = $data[$value];
        }

        return $retval;
    }

    public function xGetContentAction()
    {
        $this->request->defineParams(['locations' => ['type' => 'json']]);

        $this->response->data($this->getContent(['locations' => $this->request->getParam('locations')]));
    }

    public function xGetLocationsAction()
    {
        $this->response->data(
            ['locations' => self::loadController('Platforms')->getCloudLocations(SERVER_PLATFORMS::EC2, false)]
        );
    }

    public function getUsedLocations()
    {
        $locationResults = $this->db->Execute(
            "SELECT DISTINCT(value) FROM server_properties WHERE server_id IN (SELECT server_id FROM servers WHERE env_id=?) AND `name`= ?",
            array($this->getEnvironmentId(), EC2_SERVER_PROPERTIES::REGION)
        );
        $neededLocations = array();
        while ($location = $locationResults->fetchRow()) {
            $neededLocations[] = $location['value'];
        }

        return $neededLocations;
    }
}
