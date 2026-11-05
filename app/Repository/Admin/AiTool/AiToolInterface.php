<?php

namespace App\Repository\Admin\AiTool;

interface AiToolInterface
{
    /** يطابق رقم واتساب مع عميل نشط واحد بالضبط */
    public function matchClient(string $phone): array;

    /** تعريف الأدوات كما يطلبها Claude API */
    public function definitions(): array;

    /** ينفّذ أداة لعميل محدد (يحدده الكود، لا النموذج) */
    public function run(string $name, array $input, int $clientId): array;
}
