<?php

use App\Domain\Admin\Providers\AdminServiceProvider;
use App\Domain\Attendance\Providers\AttendanceServiceProvider;
use App\Domain\Event\Providers\EventServiceProvider;
use App\Domain\Exam\Providers\ExamServiceProvider;
use App\Domain\Grading\Providers\GradingServiceProvider;
use App\Domain\Identity\Providers\IdentityServiceProvider;
use App\Domain\Question\Providers\QuestionServiceProvider;
use App\Domain\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    IdentityServiceProvider::class,
    AdminServiceProvider::class,
    EventServiceProvider::class,
    ExamServiceProvider::class,
    AttendanceServiceProvider::class,
    QuestionServiceProvider::class,

    // Grading terakhir, dan urutannya bukan kerapian: provider ini mengikat
    // `PenilaiPengerjaan` yang dipakai `MesinUjian` milik Attendance. Keduanya
    // diikat di container, bukan diimpor di antara satu sama lain, sehingga
    // urutannya sebenarnya tidak menentukan - tetapi membaca daftar ini dari
    // atas ke bawah menjelaskan arah ketergantungannya.
    GradingServiceProvider::class,
];
