<?php

namespace Custom\Purchased\Test\Unit\Model;

use Custom\Purchased\Model\Purchased;
use PHPUnit\Framework\TestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Class PurchasedTest
 *
 * Unit test for the Purchased model.
 */
class PurchasedTest extends TestCase
{
    /**
     * @var Purchased
     */
    private $purchasedModel;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $resourceModelMock;

    protected function setUp(): void
    {
        // Mock the Resource Model
        $this->resourceModelMock = $this->createMock(\Custom\Purchased\Model\ResourceModel\Purchased::class);

        // Use ObjectManager to instantiate the model with dependencies
        $objectManager = new ObjectManager($this);
        $this->purchasedModel = $objectManager->getObject(
            Purchased::class,
            []
        );
    }

    public function testConstruct()
    {
        $this->assertInstanceOf(Purchased::class, $this->purchasedModel);
    }
}
