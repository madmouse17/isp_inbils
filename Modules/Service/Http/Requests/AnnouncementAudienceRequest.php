<?php

namespace Modules\Service\Http\Requests;

use Illuminate\Support\Arr;

/**
 * The targeting half of an announcement, asked before it is saved: how many customers would it reach. Same permission,
 * same company-scoped location and category ids (public ids on the wire) and the same "a targeted audience needs a
 * target" rule as saving it, so the count can never be asked about what saving would refuse.
 */
class AnnouncementAudienceRequest extends SaveAnnouncementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Arr::only(parent::rules(), ['audience', 'location_ids', 'location_ids.*', 'service_package_category_ids', 'service_package_category_ids.*']);
    }
}
