/** Codes de type d'opération renvoyés par l'API (LabelNormalizer) et leurs libellés. */
export const TYPES_OPERATION: Record<string, string> = {
  CARTE: 'Paiement par carte',
  PRELEVEMENT: 'Prélèvement',
  VIREMENT_RECU: 'Virement reçu',
  VIREMENT_EMIS: 'Virement émis',
  RETRAIT: 'Retrait au distributeur',
  REGLEMENT: 'Règlement',
  REMISE_CHEQUE: 'Remise de chèque',
  CHEQUE: 'Chèque',
  FRAIS: 'Frais',
  COTISATION: 'Cotisation',
  INTERETS: 'Intérêts',
  REMBOURSEMENT_PRET: 'Remboursement de prêt',
  REJET: 'Rejet',
  REGULARISATION: 'Régularisation',
  AVOIR: 'Avoir',
  AUTRE: 'Autre',
};
