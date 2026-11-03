<?php

namespace App\Traits;

use App\Constants\Constants;

trait ProcurementControllerTrait
{
    private function denied()
    {
        return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
    }

    private function respond(array $result, string $message, $code = Constants::RESPONSE_SUCCESS)
    {
        if ($result['status']) {
            return $this->successResponse($message, $result['data'], $code);
        }

        return $this->failureResponse($result['message'], $result['data'], $result['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }
}
