<?php

namespace App\Domain\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ganti sandi sendiri - PUT /siswa/akun/sandi.
 *
 * =====================================================================
 * NAMA FIELD SENGAJA BAHASA INGGRIS, LABELNYA BAHASA INDONESIA
 * =====================================================================
 * Ini bukan kelalaian penerjemahan. `Handler::$dontFlash` di framework
 * (`Illuminate\Foundation\Exceptions\Handler`) memuat tepat tiga nama:
 *
 *     ['current_password', 'password', 'password_confirmation']
 *
 * Field dengan nama itu DIBUANG dari `withInput()` sebelum sesi menyimpannya,
 * sehingga kegagalan validasi tidak pernah menulis sandi ke `_old_input`.
 *
 * Dengan nama `sandi_lama` / `sandi_baru`, setiap kali siswa salah ketik satu
 * huruf, KETIGA sandi - termasuk yang benar - ditulis sebagai teks terbuka ke
 * dalam sesi, lalu dikirim kembali ke peramban sebagai `defaultValue`. Itu
 * bukan soal kerapian; itu kebocoran, dan testnya ada.
 *
 * Yang berbahasa Indonesia adalah yang DILIHAT pengguna: label di `attributes()`
 * dan pesan di `messages()`.
 *
 * =====================================================================
 * Mengapa FormRequest, bukan $request->validate() sebaris
 * =====================================================================
 * Repo ini tidak punya direktori `lang/` (`APP_LOCALE=id` tetapi
 * `APP_FALLBACK_LOCALE=en`), sehingga pesan bawaan keluar dalam bahasa
 * Inggris. Lima pesan di bawah harus ditulis eksplisit, dan lima pesan itu
 * lebih terbaca di berkasnya sendiri - preseden yang sama dengan
 * `LoginRequest` dan `SimpanSoalRequest`.
 */
class GantiSandiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `current_password` adalah rule BAWAAN Laravel
            // (`ValidatesAttributes::validateCurrentPassword`). Memakai
            // closure `Hash::check` sendiri berarti menempelkan galatnya ke
            // field dengan tangan, dan galat yang ditempelkan dengan tangan
            // adalah galat yang lupa ditempelkan saat field-nya berubah.
            'current_password' => ['required', 'string', 'current_password'],

            // `confirmed` membaca `password_confirmation`.
            //
            // `different:current_password` menutup dua hal sekaligus: siswa
            // yang mengganti sandinya ke sandi yang sama akan terus melihat
            // peringatan "sandi bawaan" di laman Akun, dan setiap penyimpanan
            // semacam itu menagih satu `Hash::check` tambahan tanpa mengubah
            // apa pun.
            'password' => ['required', 'string', 'min:6', 'max:255', 'confirmed', 'different:current_password'],
        ];
    }

    /**
     * Label yang muncul di pesan galat - "Sandi lama" untuk `current_password`.
     *
     * Tanpa ini, pesannya berbunyi "The current password field is required."
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'Sandi lama',
            'password' => 'Sandi baru',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Sandi lama wajib diisi.',
            'current_password.current_password' => 'Sandi lama tidak cocok.',
            'password.required' => 'Sandi baru wajib diisi.',
            'password.min' => 'Sandi baru minimal :min karakter.',
            'password.max' => 'Sandi baru terlalu panjang.',
            'password.confirmed' => 'Ulangi sandi baru dengan isi yang sama.',
            'password.different' => 'Sandi baru harus berbeda dari sandi lama.',
        ];
    }
}
