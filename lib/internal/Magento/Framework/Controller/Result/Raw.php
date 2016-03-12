<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Controller\Result;

use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\AbstractResult;

/**
 * A result that contains raw response - may be good for passing through files,
 * returning result of downloads or some other binary contents
 */
class Raw extends AbstractResult
{
    /**
     * @var string
     */
    protected $contents;

    /**
     * @param string $contents
     * @return $this
     */
    public function setContents($contents)
    {
        $this->contents = $contents;
        return $this;
    }

    /**
     * @param HttpResponseInterface|ResponseInterface $response
     * @return $this
     */
    protected function render(ResponseInterface $response)
    {
        return $this->renderHttpResponse($response);
    }

    /**
     * @param HttpResponseInterface $httpResponse
     * @return $this
     */
    private function renderHttpResponse(HttpResponseInterface $httpResponse)
    {
        $httpResponse->setBody($this->contents);
        return $this;
    }
}
