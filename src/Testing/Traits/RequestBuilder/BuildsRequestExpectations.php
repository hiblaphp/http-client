<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

trait BuildsRequestExpectations
{
    abstract protected function getRequest();

    /**
     * Expect a specific header in the request.
     */
    public function expectHeader(string $name, string $value): static
    {
        $this->getRequest()->addHeaderMatcher($name, $value);

        return $this;
    }

    /**
     * Expect multiple headers in the request.
     */
    public function expectHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->expectHeader($name, $value);
        }

        return $this;
    }

    /**
     * Expect a specific body pattern in the request.
     */
    public function expectBody(string $pattern): static
    {
        $this->getRequest()->setBodyMatcher($pattern);

        return $this;
    }

    /**
     * Expect specific JSON data in the request body.
     */
    public function expectJson(array $data): static
    {
        $this->getRequest()->setJsonMatcher($data);

        return $this;
    }

    /**
     * Expect specific cookies to be present in the request.
     */
    public function expectCookies(array $expectedCookies): static
    {
        foreach ($expectedCookies as $name => $value) {
            $this->getRequest()->addHeaderMatcher('cookie', $name . '=' . $value);
        }

        return $this;
    }
}
