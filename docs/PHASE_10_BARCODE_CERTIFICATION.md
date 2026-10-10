# Phase 10 barcode certification

Baseline: `c74c9a4e0b4135055dbb8ed7d3e3715d2ddba24f`. This package adds tests and a software decoder only; the shipped barcode implementation is unchanged. Independent lead execution PASS: nine tests / 300 assertions, 34.197 seconds, 92 MiB. Owned schema `accounting_p8_tmp_60326793f84c` cleaned. The worker reported 303 assertions before lead portability corrections; that is a separate run.

## Newly executed scope

Actual application PDFs are rasterized at 300 dpi with PyMuPDF and decoded cell by cell with zxing-cpp. This is software scanner evidence from synthetic PDFs. Printed-label hardware scanning, printer scaling and physical label stock are NOT VERIFIED.

| Symbol | Arabic 3x8 sheet | English 2x7 sheet | Expected decoded identity |
|---|---:|---:|---|
| Code 128, piece | 6 | 3 | `PIECE-SKU-001`, stored piece Unit and conversion1 |
| Code 128, carton | 6 | 3 | `CARTON-SKU-001`, stored carton Unit and exact conversion12 |
| EAN-13 | 4 | 3 | `9780201379624` |
| EAN-8 | 3 | 2 | `96385074` |
| UPC-A | 3 | 2 | `036000291452` |
| UPC-E | 2 | 1 | Stored caption `04252614`; decoder output normalized to expansion `042100005264` |

The two sheets contain24 and14 labels. A separate15-label sheet verifies all10 suffix digits for number system0, and digits0,1,3,4,5 for number system1. Number-system1 digits2,6,7,8,9 are not separately decoded in this new sample. Raw decoder format/text are retained alongside normalized values; no generic behavior is assumed for other scanner models.

| Number system | Stored UPC-E | Expected UPC-A expansion |
|---|---|---|
| 0 | `01234505` | `012000003455` |
| 0 | `04252614` | `042100005264` |
| 0 | `01234523` | `012200003453` |
| 0 | `01234531` | `012300000451` |
| 0 | `01234543` | `012340000053` |
| 0 | `01234558` | `012345000058` |
| 0 | `01234565` | `012345000065` |
| 0 | `01234572` | `012345000072` |
| 0 | `01234589` | `012345000089` |
| 0 | `01234596` | `012345000096` |
| 1 | `11234502` | `112000003452` |
| 1 | `14252611` | `142100005261` |
| 1 | `11234538` | `112300000458` |
| 1 | `11234540` | `112340000050` |
| 1 | `11234555` | `112345000055` |

New focused service cases verify piece/carton and null-Unit label projections, foreign Company refusal, inactive Product/ProductUnit refusal, empty batches,100-symbol/500-label caps, Code128 character/length refusal, EAN-13/EAN-8/UPC-A/UPC-E invalid check digits, invalid UPC-E number system/length, and unchanged posting-batch/stock-movement counts. These tests do not claim new soft-delete, anonymous-actor or overwide-symbol coverage; existing regressions remain separately attributed until actually rerun. Unit caption/conversion checks do not by themselves certify every transactional barcode-entry workflow.

## Carried evidence and reproducibility

[Phase9 source acceptance](PHASE_9_SOURCE_ACCEPTANCE_HANDOFF.md) reports24/24 AR,14/14 EN and49/49 UPC-E decodes. Those aggregate reports are carried evidence; they are not counted as newly executed Phase10 labels. The new per-cell manifests identify each symbol in the53-label sample.

Use `tests/Support/run-phase8-disposable.php` under explicit owned-schema opt-in. Set `PHASE10_PYTHON_BINARY` when Python is not on PATH and, when needed, `PHASE10_PDFTOOLS_DIR` to an existing directory containing PyMuPDF, Pillow and zxing-cpp. The helper installs no dependencies. Missing tools fail the decoder test; they do not produce PASS. Invoke the runner with `tests/Feature/Phase10/BarcodeCertificationTest.php`, then focused Pint.

Ignored evidence resides in `.ai/phase10-barcode/`: three PDFs, per-cell manifests and decode JSON. All 53 cells decoded successfully. Lead inspected the actual Arabic 3x8 and English 2x7 raster sheets: readable Product/Unit captions and distinct piece/carton identity, without clipping. The 15-label UPC-E sheet has decode evidence; physical scanning remains NOT VERIFIED. No production fixtures, economic writer changes, external provider access, merge or deployment are authorized by this evidence.
