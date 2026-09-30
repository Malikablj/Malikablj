<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AttachmentRepository;
use App\Repositories\PrRepository;
use App\Services\AttachmentService;
use App\Services\PrPolicy;
use App\Services\PrService;

final class AttachmentController extends Controller
{
    public function download(Request $request, int $id): Response
    {
        $user = $this->user();
        $attachment = $this->findOr404((new AttachmentRepository())->find($id));
        $pr = $this->findOr404((new PrRepository())->find((int) $attachment['pr_id']));
        $this->authorize((new PrPolicy())->canView($user, $pr));

        $path = (new AttachmentService())->absolutePath($attachment);

        return Response::file($path, (string) $attachment['original_name'], (string) $attachment['mime_type'])
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function delete(Request $request, int $id): Response
    {
        $prId = (new PrService())->deleteAttachment($this->user(), $id);

        return $this->redirect('/pr/' . $prId, 'Lampiran dihapus.');
    }
}
