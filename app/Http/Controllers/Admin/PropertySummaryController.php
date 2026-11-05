<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Repository\Admin\PropertySummary\PropertySummaryInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class PropertySummaryController extends Controller
{
    use ResponseTrait;

    protected $propertySummary;

    public function __construct(PropertySummaryInterface $propertySummary)
    {
        $this->propertySummary = $propertySummary;
    }

    public function index(Request $request)
    {
        if (!PermissionHelper::checkPermission(Constants::VIEW_PROPERTIES)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }

        $request->validate(['property_id' => ['nullable', 'integer', 'min:1']]);

        $data = $this->propertySummary->summary($request);

        if ($data['status']) {
            return $this->successResponse(__('messages.properties_fetched'), $data['data']);
        }

        return $this->failureResponse($data['message'], $data['data'], $data['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }
}
