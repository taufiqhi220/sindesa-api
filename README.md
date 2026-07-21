# Sindesa API

REST API backend untuk aplikasi mobile **Sindesa** (Sistem Informasi Desa). API ini menghubungkan aplikasi Android dengan database MySQL yang sama digunakan oleh website Laravel Sindesa.

## Tech Stack

- **PHP** (native, tanpa framework)
- **MySQL** (via mysqli)
- **Laravel Storage** (untuk file upload — shared dengan web app)

## Konfigurasi

Edit `db_config.php` untuk mengatur koneksi database:

```php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "db_sindesa";
```

## Endpoint API

### Autentikasi
| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| POST | `login_warga.php` | Login warga (NIK/Email + Password) |
| POST | `register_warga.php` | Registrasi akun warga baru |

### Profil
| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| GET | `get_profil.php?nik=xxx` | Ambil data profil warga |
| POST | `update_profil.php` | Update profil warga |

### Dashboard
| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| GET | `dashboard_stats.php?nik=xxx` | Statistik dashboard (total pengajuan, proses, dll) |

### Riwayat & Detail Pengajuan
| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| GET | `get_riwayat.php?nik=xxx` | Daftar riwayat pengajuan surat |
| GET | `get_detail_pengajuan.php?id=xxx` | Detail satu pengajuan |
| GET | `cetak_surat.php?id=xxx` | Download/view PDF surat (status harus selesai) |

### Submit Pengajuan Surat (POST)
| Endpoint | Jenis Surat |
|----------|-------------|
| `submit_ktp.php` | Pengantar KTP |
| `submit_kk.php` | Pengantar KK |
| `submit_akta_lahir.php` | Pengantar Akta Lahir |
| `submit_domisili.php` | Surat Keterangan Domisili |
| `submit_kematian.php` | Surat Keterangan Kematian |
| `submit_pindah.php` | Surat Keterangan Pindah |
| `submit_belum_menikah.php` | Surat Belum Menikah |
| `submit_beda_nama.php` | Surat Beda Nama |
| `submit_janda_duda.php` | Surat Keterangan Janda/Duda |
| `submit_kehilangan.php` | Surat Keterangan Kehilangan |
| `submit_skck.php` | Pengantar SKCK |
| `submit_sktm.php` | SKTM (Tidak Mampu) |
| `submit_usaha.php` | Surat Keterangan Usaha |
| `submit_penghasilan.php` | Surat Keterangan Penghasilan |
| `submit_izin_keramaian.php` | Surat Izin Keramaian |

### Data Wilayah (GET)
| Endpoint | Deskripsi |
|----------|-----------|
| `get_provinces.php` | Daftar provinsi |
| `get_cities.php?province_id=xxx` | Daftar kota/kabupaten |
| `get_districts.php?regency_id=xxx` | Daftar kecamatan |
| `get_villages.php?district_id=xxx` | Daftar kelurahan/desa |

## Response Format

Semua endpoint mengembalikan JSON:

```json
{
  "success": true,
  "message": "Operasi berhasil",
  "data": { ... }
}
```

## File Helper

| File | Fungsi |
|------|--------|
| `api_bootstrap.php` | Setup global (error handling, CORS, output buffering) |
| `db_config.php` | Konfigurasi koneksi database |
| `upload_helper.php` | Proses upload file dari Android |
| `merge_helper.php` | Merge data user + form + file upload |

## Deployment

API ini didesain untuk berjalan di direktori yang sejajar dengan folder Laravel Sindesa:

```
/www/
├── sindesa/          ← Laravel web app
├── sindesa_api/      ← API ini
```
