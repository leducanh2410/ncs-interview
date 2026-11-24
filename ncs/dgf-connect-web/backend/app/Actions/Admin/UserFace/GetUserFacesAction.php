<?php

namespace App\Actions\Admin\UserFace;

use App\Actions\ActionResult;
use App\Models\UserFace;

class GetUserFacesAction
{
    public function __invoke(array $conditions): ActionResult
    {
        $userFaces = UserFace::getPaginateFiltered(
            $conditions,
            ['user'],
            ['id' => 'asc'],
            $conditions['page_size'] ?? null
        );

        return new ActionResult(data: $userFaces);
    }
}
