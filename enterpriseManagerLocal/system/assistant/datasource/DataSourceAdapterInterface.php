<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource;

interface DataSourceAdapterInterface
{
    public function getDriver(): string;
    public function getLabel(): string;

    /**
     * Tests only connectivity/access. Passwords may be present in $runtimeConfig,
     * but must never be returned from this method.
     */
    public function test(array $runtimeConfig): ConnectionTestResult;

    /**
     * @return list<array{name:string,type:string,label:string,meta?:array}>
     */
    public function discover(array $runtimeConfig): array;
}
