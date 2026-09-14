<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $subject ?? 'Undangan Rekrutmen - OCEAN SPACE' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f9; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#334155; -webkit-font-smoothing:antialiased; line-height:1.6;">
  <table cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f6f9; padding:24px 8px;">
    <tr>
      <td align="center">
        <!-- Main Document Canvas -->
        <table cellpadding="0" cellspacing="0" width="100%" style="max-width:560px; background-color:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #e2e8f0; box-shadow:0 2px 6px -1px rgba(0,0,0,0.05);">
          
          <!-- Content Body -->
          <tr>
            <td style="padding:36px 32px 32px 32px;">
              
              <!-- 1. Centered Ocean Space Logo (Hosted HTTPS Logo - No attachment pill in Gmail) -->
              <div style="text-align:center; margin-bottom:24px;">
                <img
                  src="{{ !empty($logo_url) ? $logo_url : 'https://oceanspace.co.id/images/logo-color.png' }}"
                  alt="OCEAN SPACE"
                  height="36"
                  style="height:36px; max-height:36px; width:auto; display:inline-block; border:0;"
                />
              </div>

              <!-- 2. Centered Big Header Title & Position -->
              <div style="text-align:center; margin-bottom:28px;">
                <h1 style="margin:0; font-size:22px; font-weight:800; color:#1e3a8a; line-height:1.3; letter-spacing:-0.3px;">
                  {{ $badge_text ?? 'Undangan Seleksi Kerja' }}
                </h1>
                @if (!empty($position_title))
                <div style="font-size:17px; font-weight:600; color:#334155; margin-top:4px;">
                  {{ $position_title }}
                </div>
                @endif
              </div>

              <!-- 3. Salutation -->
              <div style="font-size:14.5px; font-weight:600; color:#0f172a; margin-bottom:14px;">
                Dear {{ $recipient_name ?? 'Kandidat' }},
              </div>

              <!-- 4. Body Message -->
              <div style="font-size:14px; line-height:1.7; color:#334155; margin-bottom:24px; white-space:pre-line;">
{{ $body_message }}
              </div>

              <!-- Attachment Notice if Present -->
              @if (!empty($has_attachment))
              <div style="margin:20px 0 24px 0; background-color:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px 16px; font-size:13px; color:#166534; line-height:1.5;">
                <strong style="color:#15803d;">📎 Dokumen Terlampir:</strong> Berkas Offering Letter resmi telah dilampirkan pada email ini dalam format PDF. Silakan unduh dan tinjau rincian dokumen terlampir.
              </div>
              @endif

              <!-- 5. Structured Details Box (Clean Soft Blue Card) -->
              @if (!empty($info_items) && count($info_items) > 0)
              <div style="margin:24px 0 28px 0; background-color:#f8fafc; border-radius:12px; padding:20px 22px; border:1px solid #e2e8f0;">
                @foreach ($info_items as $index => $item)
                <div style="padding:{{ $index > 0 ? '12px 0 0 0' : '0' }}; {{ $index > 0 ? 'border-top:1px solid #edf2f7;' : '' }}">
                  <div style="font-size:10.5px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:0.6px; margin-bottom:3px;">
                    {{ $item['label'] }}
                  </div>
                  <div style="font-size:14px; font-weight:{{ in_array($item['label'], ['Posisi Lowongan', 'Posisi', 'POSISI LOWONGAN']) ? '800' : '600' }}; color:{{ in_array($item['label'], ['Posisi Lowongan', 'Posisi', 'POSISI LOWONGAN']) ? '#1e3a8a' : '#0f172a' }}; line-height:1.4; word-break:break-all;">
                    @if (str_starts_with($item['value'], 'http://') || str_starts_with($item['value'], 'https://'))
                      <a href="{{ $item['value'] }}" target="_blank" style="color:#0066FF; text-decoration:underline; font-weight:600;">{{ $item['value'] }}</a>
                    @else
                      {{ $item['value'] }}
                    @endif
                  </div>
                </div>
                @endforeach
              </div>
              @endif

              <!-- 6. Call to Action Button (Ocean Space Blue Full-Width/Centered) -->
              @if (!empty($action_url))
              <div style="margin:26px 0 28px 0;">
                <a
                  href="{{ $action_url }}"
                  target="_blank"
                  style="display:block; background-color:#0066FF; color:#ffffff; font-size:14px; font-weight:700; text-decoration:none; padding:14px 20px; border-radius:10px; letter-spacing:0.2px; text-align:center; box-shadow:0 3px 6px -1px rgba(0,102,255,0.3);"
                >
                  {{ !empty($action_label) ? $action_label : 'Buka Tautan Akses' }} &rarr;
                </a>
                <div style="font-size:11.5px; color:#94a3b8; margin-top:10px; text-align:center; line-height:1.4;">
                  Tautan alternatif: <a href="{{ $action_url }}" target="_blank" style="color:#0066FF; text-decoration:none; word-break:break-all;">{{ $action_url }}</a>
                </div>
              </div>
              @endif

              <!-- 7. Special Note -->
              @if (!empty($special_note))
              <div style="font-size:12.5px; line-height:1.6; color:#334155; margin-bottom:22px; padding:12px 16px; background-color:#f0f7ff; border-left:3px solid #0066FF; border-radius:0 8px 8px 0;">
                <strong style="color:#0066FF;">Catatan:</strong> {{ $special_note }}
              </div>
              @endif

              <!-- 8. Sign-off -->
              <div style="margin-top:28px; font-size:13.5px; line-height:1.5; color:#334155;">
                <div>Warmest wishes,</div>
                <div style="font-weight:800; color:#0f172a; margin-top:4px;">Human Capital Department</div>
                <div style="color:#0066FF; font-weight:700; font-size:13px;">OCEAN SPACE</div>
                <div style="color:#94a3b8; font-size:11.5px; margin-top:2px;">
                  <a href="https://oceanspace.co.id/" target="_blank" style="color:#64748b; text-decoration:none;">www.oceanspace.co.id</a>
                </div>
              </div>

            </td>
          </tr>

          <!-- Clean Footer -->
          <tr>
            <td style="padding:16px 24px; border-top:1px solid #f1f5f9; font-size:11px; color:#94a3b8; text-align:center; line-height:1.4; background-color:#fcfdfd;">
              Email resmi Sistem Rekrutmen OCEAN SPACE &bull; Harap tidak membalas email otomatis ini.
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
