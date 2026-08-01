# Catalogue d'événements MASANTÉ (EDA)

Référence unique des événements du bus (RabbitMQ métier / Kafka flux massifs — CDC_03 §8, CDC_12 §5). Format d'enveloppe : `eventId`, `eventType`, `eventVersion`, `timestamp`, `producer`, `correlationId`, `payload` (identifiants, **pas** de contenu clinique — minimisation, règle EDA n°8). Consommateurs **idempotents** ; publication via **Outbox** (après commit) ; erreurs → **DLQ**.

> Statut P0 : **catalogue de référence** (« prêt à activer »). Aucun bus déployé en MVP monolithe.

## Domain Events
| Événement | Producteur | Consommateurs typiques |
|---|---|---|
| PatientCreated / PatientUpdated / PatientMerged | patient / referential (MPI) | ehr, notification, analytics |
| AppointmentRequested / PreValidated / Confirmed / Cancelled / Completed | appointment | payment, notification, ehr |
| ConsultationStarted / Completed / Closed · DiagnosisRecorded | consultation | ehr, billing, ai, analytics |
| PrescriptionCreated / Validated | prescription | pharmacy, ehr, notification |
| LaboratoryResultAvailable | laboratory | ehr, notification, ai |
| ImagingReportPublished · DICOMStudyCompleted | radiology | ehr, notification |
| TriagePerformed | triage | analytics, ehr |
| RiskDetectedByAI | ai | notification, ehr |
| EmergencyCallReceived · AmbulanceDispatched | emergency | notification, hospital-admin |

## Integration / Payment Events
| Événement | Producteur |
|---|---|
| PaymentInitiated / Pending / Confirmed / Failed / Cancelled / Refunded | payment |
| InvoiceIssued / Corrected · WalletCredited / Debited | billing / wallet |
| InsuranceClaimSubmitted / Approved / Rejected · SettlementExecuted · FraudSuspected | insurance / settlement |
| PharmacyStockSynchronized · StockShortageDetected | pharmacy |
| ProtocolVersionPublished | protocol (→ réindexation RAG, invalidation cache) |

## Technical Events
`ServiceUnavailable` · `DatabaseBackupCompleted` · `ModelTrainingFinished`
