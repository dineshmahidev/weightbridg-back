<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Movement & Weighbridge Register - {{ $dateFrom }} to {{ $dateTo }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <!-- html2pdf.js for direct mobile A4 PDF downloads without physical printer -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #525659;
            color: #0f172a;
            padding: 70px 10px 40px 10px;
            font-size: 11px;
        }

        /* Top Action Toolbar */
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #0f172a;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            z-index: 9999;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            flex-wrap: wrap;
            gap: 10px;
        }

        .toolbar-title {
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .badge-kpi {
            background: #ffd84d;
            color: #000;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 800;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-download {
            background: #10b981;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4);
        }

        .btn-download:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        .btn-share {
            background: #25d366;
            color: #ffffff;
        }

        .btn-share:hover {
            background: #1ebe5d;
            transform: translateY(-1px);
        }

        .btn-primary {
            background: #ffd84d;
            color: #0b0b0b;
        }

        .btn-primary:hover {
            background: #f5c518;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        /* Mobile Notification Tip */
        .mobile-tip-banner {
            max-width: 1100px;
            margin: 0 auto 16px auto;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* A4 Landscape Paper Container */
        .a4-landscape-page {
            width: 297mm;
            min-height: 210mm;
            margin: 0 auto 30px auto;
            background: #ffffff;
            box-shadow: 0 0 24px rgba(0, 0, 0, 0.35);
            padding: 12mm 15mm;
            position: relative;
        }

        @media (max-width: 900px) {
            body {
                padding: 110px 6px 30px 6px;
            }
            .toolbar {
                padding: 8px 12px;
            }
            .toolbar-actions {
                width: 100%;
            }
            .btn {
                flex: 1 1 auto;
                font-size: 12px;
                padding: 8px 10px;
                justify-content: center;
            }
            .a4-landscape-page {
                width: 100%;
                max-width: 100%;
                overflow-x: auto;
                padding: 10px 8px;
                box-shadow: none;
                border-radius: 8px;
            }
        }

        /* Header Block */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }

        .company-title {
            font-size: 18px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: -0.3px;
            color: #0f172a;
        }

        .report-subtitle {
            font-size: 11px;
            color: #475569;
            margin-top: 2px;
        }

        .header-meta {
            text-align: right;
            font-size: 11px;
        }

        .date-range-badge {
            display: inline-block;
            background: #0f172a;
            color: #ffd84d;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 800;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
        }

        /* Summary KPIs */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 12px;
        }

        .kpi-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
        }

        .kpi-label {
            font-size: 9px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kpi-val {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            font-family: 'JetBrains Mono', monospace;
            margin-top: 2px;
        }

        /* Table */
        .register-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .register-table th {
            background: #0f172a;
            color: #ffffff;
            padding: 6px 8px;
            text-align: left;
            font-weight: 700;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .register-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            color: #1e293b;
        }

        .register-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
        }

        .plate-box {
            background: #fef08a;
            color: #000;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-block;
            font-weight: 800;
        }

        .status-pill {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-completed { background: #dcfce7; color: #15803d; }
        .status-open { background: #fef3c7; color: #b45309; }
        .status-cancelled { background: #fee2e2; color: #b91c1c; }

        /* Summary Total Row */
        .total-row {
            background: #0f172a !important;
            color: #ffffff !important;
            font-weight: 800;
        }

        .total-row td {
            color: #ffffff !important;
            border: none;
            padding: 8px;
            font-size: 11px;
        }

        /* Signatures */
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 24px;
            padding-top: 10px;
        }

        .sig-block {
            width: 200px;
            text-align: center;
            border-top: 1px solid #0f172a;
            padding-top: 4px;
            font-size: 10px;
            font-weight: 700;
        }

        /* Exact A4 Print Optimization */
        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .toolbar {
                display: none !important;
            }

            .a4-landscape-page {
                width: 100% !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            @page {
                size: 297mm 210mm;
                margin: 8mm;
            }
        }
    </style>
</head>
<body>

    <!-- Action Toolbar -->
    <div class="toolbar">
        <div class="toolbar-title">
            <span>Vehicle Movement & Weighbridge Register</span>
            <span class="badge-kpi">{{ count($movements) }} Entries</span>
        </div>
        <div class="toolbar-actions">
            <button id="btn-download-bulk-pdf" onclick="downloadBulkA4PDF()" class="btn btn-download">
                📥 Download A4 PDF
            </button>
            <button onclick="shareBulkRegister()" class="btn btn-share">
                📲 Share WhatsApp
            </button>
            <button onclick="window.print()" class="btn btn-primary">
                🖨️ Print / Save PDF
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                ✕ Close
            </button>
        </div>
    </div>

    <!-- Mobile Helpful Tip Banner -->
    <div class="mobile-tip-banner">
        <span style="font-size: 16px;">📱</span>
        <span>
            <strong>Mobile Tip:</strong> No physical printer needed! Tap <strong>"Download A4 PDF"</strong> to save this full register directly as an A4 Landscape PDF to your phone.
        </span>
    </div>

    <!-- Official A4 Landscape Document -->
    <div class="a4-landscape-page">
        <!-- Header -->
        <div class="report-header">
            <div>
                <h1 class="company-title">{{ $company->name ?? 'INDUSTRIAL VEHICLE MOVEMENT & WEIGHBRIDGE SYSTEM' }}</h1>
                <p class="report-subtitle">
                    Comprehensive Gate In/Out, Quality Inspection, and Dual Electronic Weighment Register
                </p>
            </div>
            <div class="header-meta">
                <span class="date-range-badge">
                    PERIOD: {{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }} TO {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}
                </span>
                <div style="margin-top: 4px; color: #64748b;">Generated: {{ now()->format('d M Y, h:i A') }}</div>
            </div>
        </div>

        <!-- Summary KPIs -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-label">Total Movements</div>
                <div class="kpi-val">{{ count($movements) }}</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Completed Trips</div>
                <div class="kpi-val" style="color: #15803d;">
                    {{ $movements->where('status', 'completed')->count() }}
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Inside Premises</div>
                <div class="kpi-val" style="color: #b45309;">
                    {{ $movements->whereIn('status', \App\Support\MovementStatus::OPEN)->count() }}
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Total Net Material Dispatched</div>
                <div class="kpi-val" style="color: #0f172a;">
                    {{ number_format($totalNetWeight, 2) }} kg
                </div>
            </div>
        </div>

        <!-- Register Table -->
        <table class="register-table">
            <thead>
                <tr>
                    <th style="width: 3%;">#</th>
                    <th style="width: 10%;">Movement Ref</th>
                    <th style="width: 11%;">Vehicle Plate</th>
                    <th style="width: 13%;">Driver Details</th>
                    <th style="width: 11%;">Material</th>
                    <th style="width: 10%;">Gate In</th>
                    <th style="width: 10%;">Gate Out</th>
                    <th style="width: 8%; text-align: right;">Gross (kg)</th>
                    <th style="width: 8%; text-align: right;">Tare (kg)</th>
                    <th style="width: 9%; text-align: right;">Net (kg)</th>
                    <th style="width: 7%; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($movements as $index => $m)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="mono">{{ $m->movement_ref }}</td>
                        <td>
                            <span class="plate-box mono">{{ $m->vehicle_number }}</span>
                        </td>
                        <td>
                            <strong>{{ $m->driver_name ?? '—' }}</strong><br>
                            <span style="color: #64748b;">{{ $m->driver_mobile ?? '' }}</span>
                        </td>
                        <td>{{ $m->material->name ?? 'Freight' }}</td>
                        <td>{{ $m->entry_at ? $m->entry_at->format('d/m H:i') : '—' }}</td>
                        <td>{{ $m->exit_at ? $m->exit_at->format('d/m H:i') : 'Inside' }}</td>
                        @php
                            $rowRawUnit = strtolower($m->weight_unit ?? 'kg');
                            $isRowMt = in_array($rowRawUnit, ['tonne', 'tonnes', 'mt', 'metric_ton', 'metric ton']);
                            $rowDec = $isRowMt ? 3 : 0;
                        @endphp
                        <td class="mono" style="text-align: right;">
                            {{ $m->loaded_weight ? number_format($m->loaded_weight, $rowDec) : '—' }}
                        </td>
                        <td class="mono" style="text-align: right;">
                            {{ $m->empty_weight ? number_format($m->empty_weight, $rowDec) : '—' }}
                        </td>
                        <td class="mono" style="text-align: right; font-weight: 800; color: #15803d;">
                            {{ $m->net_weight ? number_format($m->net_weight, $rowDec) : '0' }}
                        </td>
                        <td style="text-align: center;">
                            <span class="status-pill status-{{ $m->status === 'completed' ? 'completed' : ($m->status === 'cancelled' ? 'cancelled' : 'open') }}">
                                {{ $m->status === 'completed' ? 'Done' : ($m->status === 'cancelled' ? 'Cancel' : 'Active') }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 20px; color: #64748b;">
                            No vehicle movements found matching the selected date range and criteria.
                        </td>
                    </tr>
                @endforelse

                @if(count($movements) > 0)
                    <tr class="total-row">
                        <td colspan="7" style="text-transform: uppercase;">
                            TOTAL REGISTER AGGREGATE ({{ count($movements) }} MOVEMENTS)
                        </td>
                        <td class="mono" style="text-align: right;">
                            {{ number_format($movements->sum('loaded_weight'), 0) }}
                        </td>
                        <td class="mono" style="text-align: right;">
                            {{ number_format($movements->sum('empty_weight'), 0) }}
                        </td>
                        <td class="mono" style="text-align: right; color: #ffd84d;">
                            {{ number_format($totalNetWeight, 0) }} kg
                        </td>
                        <td></td>
                    </tr>
                @endif
            </tbody>
        </table>

        <!-- Signatures -->
        <div class="signatures">
            <div class="sig-block">
                Prepared By (Security In-Charge)
            </div>
            <div class="sig-block">
                Weighbridge In-Charge
            </div>
            <div class="sig-block">
                Logistics & Plant Manager
            </div>
        </div>
    </div>

    <script>
        // Direct Client-Side A4 Landscape PDF Generator
        function downloadBulkA4PDF() {
            const btn = document.getElementById('btn-download-bulk-pdf');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '⏳ Generating PDF...';
            btn.disabled = true;

            const element = document.querySelector('.a4-landscape-page');

            // Exact ISO A4 Landscape configuration (297mm x 210mm)
            const opt = {
                margin: [4, 4, 4, 4],
                filename: 'Weighbridge_Register_{{ $dateFrom }}_to_{{ $dateTo }}.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    scrollX: 0,
                    scrollY: 0,
                },
                jsPDF: {
                    unit: 'mm',
                    format: 'a4',
                    orientation: 'landscape'
                }
            };

            if (window.html2pdf) {
                html2pdf().set(opt).from(element).save().then(() => {
                    btn.innerHTML = '✓ Downloaded!';
                    setTimeout(() => {
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }, 2000);
                }).catch((err) => {
                    console.error('PDF generation error:', err);
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    window.print();
                });
            } else {
                window.print();
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }

        // Native Mobile Share
        async function shareBulkRegister() {
            const title = 'Weighbridge Register ({{ $dateFrom }} - {{ $dateTo }})';
            const text = 'Vehicle Movement & Weighbridge Register Report\nDate Range: {{ $dateFrom }} to {{ $dateTo }}\nTotal Entries: {{ count($movements) }}\nTotal Net Weight: {{ number_format($totalNetWeight, 0) }} kg';
            const url = window.location.href;

            if (navigator.share) {
                try {
                    await navigator.share({
                        title: title,
                        text: text,
                        url: url
                    });
                } catch (err) {
                    if (err.name !== 'AbortError') {
                        fallbackWhatsApp(text, url);
                    }
                }
            } else {
                fallbackWhatsApp(text, url);
            }
        }

        function fallbackWhatsApp(text, url) {
            const waUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text + '\n' + url);
            window.open(waUrl, '_blank');
        }
    </script>

    @if($autoPrint)
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                window.print();
            }, 600);
        });
    </script>
    @endif

</body>
</html>
