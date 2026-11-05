<?php

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Satu-satunya penjaga tenant di lapisan query.
 *
 * ---------------------------------------------------------------------
 * Mengapa `if` di baris pertama itu menentukan hidup-matinya login
 * ---------------------------------------------------------------------
 * Saat login belum ada user, jadi SchoolResolver mengembalikan null. Bila
 * scope tetap menambahkan `where('school_id', null)`, maka Auth::attempt
 * menjalankan `where username = ? and school_id is null` dan SETIAP login
 * gagal - tanpa pesan galat yang menjelaskan apa pun, karena hasilnya cuma
 * query kosong.
 *
 * Jadi null berarti "belum ada konteks", bukan "sekolah null". Scope diam.
 *
 * ---------------------------------------------------------------------
 * Mengapa resolver di-resolve di dalam apply(), bukan di constructor
 * ---------------------------------------------------------------------
 * Atribut #[\ScopedBy] membuat instance scope dengan `new SchoolScope()` -
 * tanpa argumen. Constructor yang meminta SchoolResolver akan meledak pada
 * query pertama. Karena itu ketergantungannya diambil dari container di
 * sini, saat sudah benar-benar dibutuhkan.
 */
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $schoolId = app(SchoolResolver::class)->id();

        if ($schoolId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('school_id'), $schoolId);
    }
}
