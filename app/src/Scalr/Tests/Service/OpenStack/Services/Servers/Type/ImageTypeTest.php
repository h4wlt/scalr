<?php
namespace Scalr\Tests\Service\OpenStack\Services\Servers\Type;

use Scalr\Service\OpenStack\Services\Servers\Type\ImageType;
use Scalr\Tests\Service\OpenStack\OpenStackTestCase;
use ReflectionClass;

/**
 * ImageTypeTest
 *
 * @author   Vitaliy Demidov  <vitaliy@scalr.com>
 * @since    12.12.2012
 */
class ImageTypeTest extends OpenStackTestCase
{

    const TYPE_CLASS_NAME = 'Services\\Servers\\Type\\ImageType';

    public function provider()
    {
        $arr = array(
            array('invalid_name', '', true)
        );
        $ref = new ReflectionClass($this->getOpenStackClassName(self::TYPE_CLASS_NAME));
        $len = strlen('TYPE_');
        foreach ($ref->getConstants() as $name => $value) {
            $arr[] = array(lcfirst($this->camelize(substr($name, $len))), $value, false);
        }
        return $arr;
    }

    /**
     * @test
     * @dataProvider provider
     */
    public function testInit($name, $value, $exception)
    {
        if ($exception) {
            try{
                $status = ImageType::$name();
                $this->assertTrue(false, 'Exception must be thrown here.');
            } catch (\BadMethodCallException $e) {
                $this->assertTrue(true);
            }
        } else {
            $status = ImageType::$name();
            $this->assertInstanceOf($this->getOpenStackClassName(self::TYPE_CLASS_NAME), $status);
            $this->assertEquals($value, (string)$status);
        }
    }
}