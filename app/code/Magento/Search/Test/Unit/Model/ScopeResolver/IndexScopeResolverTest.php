<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Search\Test\Unit\Model\ScopeResolver;

use Magento\Framework\Search\Request\Dimension;
use \Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Test for \Magento\Search\Model\ScopeResolver\IndexScopeResolver
 */
class IndexScopeResolverTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Magento\Framework\App\Resource|\PHPUnit_Framework_MockObject_MockObject
     */
    private $resource;

    /**
     * @var \Magento\Search\Model\ScopeResolver\IndexScopeResolver
     */
    private $target;

    protected function setUp()
    {
        $this->resource = $this->getMockBuilder('\Magento\Framework\App\Resource')
            ->setMethods(['getTableName'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->resource->expects($this->once())
            ->method('getTableName')
            ->willReturnArgument(0);

        $objectManager = new ObjectManager($this);

        $this->target = $objectManager->getObject(
            '\Magento\Search\Model\ScopeResolver\IndexScopeResolver',
            [
                'resource' => $this->resource,
            ]
        );
    }

    /**
     * @param string $indexName
     * @param Dimension[] $dimensions
     * @param string $expected
     * @dataProvider resolveDataProvider
     */
    public function testResolve($indexName, array $dimensions, $expected)
    {
        $result = $this->target->resolve($indexName, $dimensions);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function resolveDataProvider()
    {
        return [
            [
                'index' => 'some_index',
                'dimensions' => [],
                'expected' => 'some_index'
            ],
            [
                'index' => 'index_name',
                'dimensions' => [$this->createDimension('scope', 'name')],
                'expected' => 'index_name_scopename'
            ],
            [
                'index' => 'index_name',
                'dimensions' => [$this->createDimension('index', 20)],
                'expected' => 'index_name_index20'
            ],
            [
                'index' => 'index_name',
                'dimensions' => [$this->createDimension('dimension', 10), $this->createDimension('dimension', 20)],
                // actually you will get exception here thrown in ScopeResolverInterface
                'expected' => 'index_name_dimension10_dimension20'
            ]
        ];
    }

    /**
     * @param $name
     * @param $value
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function createDimension($name, $value)
    {
        $dimension = $this->getMockBuilder('\Magento\Framework\Search\Request\Dimension')
            ->setMethods(['getName', 'getValue'])
            ->disableOriginalConstructor()
            ->getMock();
        $dimension->expects($this->any())
            ->method('getName')
            ->willReturn($name);
        $dimension->expects($this->any())
            ->method('getValue')
            ->willReturn($value);
        return $dimension;
    }
}
