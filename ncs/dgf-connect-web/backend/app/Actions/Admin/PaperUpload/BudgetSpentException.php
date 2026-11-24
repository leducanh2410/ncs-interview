<?php

namespace App\Actions\Admin\PaperUpload;

use RuntimeException;

/**
 * Thrown inside the upload transaction when the admin's draft has no OCR reads left.
 *
 * A control-flow signal, not a failure: it rolls the transaction back before any file is
 * written, and UploadAdminPaperAction turns it into a 422 telling the client to confirm
 * the fields by hand instead of retaking the photo.
 */
class BudgetSpentException extends RuntimeException {}
