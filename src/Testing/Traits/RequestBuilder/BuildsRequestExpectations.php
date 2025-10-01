<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

trait BuildsRequestExpectations
{
    abstract protected function getRequest();

    /**
     * Expect a specific header in the request.
     */
    public function expectHeader(string $name, string $value): self
    {
        $this->getRequest()->addHeaderMatcher($name, $value);
        return $this;
    }

    /**
     * Expect multiple headers in the request.
     */
    public function expectHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->expectHeader($name, $value);
        }
        return $this;
    }

    /**
     * Expect a specific body pattern in the request.
     */
    public function expectBody(string $pattern): self
    {
        $this->getRequest()->setBodyMatcher($pattern);
        return $this;
    }

    /**
     * Expect specific JSON data in the request body.
     */
    public function expectJson(array $data): self
    {
        $this->getRequest()->setJsonMatcher($data);
        return $this;
    }

    /**
     * Expect specific cookies to be present in the request.
     */
    public function expectCookies(array $expectedCookies): self
    {
        foreach ($expectedCookies as $name => $value) {
            $this->getRequest()->addHeaderMatcher('cookie', $name . '=' . $value);
        }
        return $this;
    }
}