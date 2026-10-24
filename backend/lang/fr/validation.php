<?php

return [
    'required_if' => 'Le champ :attribute est obligatoire pour cette action.',
    'required_unless' => 'Le champ :attribute est obligatoire pour cette action.',
    'present' => 'Le champ :attribute doit être présent.',
    'boolean' => 'La valeur de :attribute doit être oui ou non.',
    'distinct' => 'Le champ :attribute contient un doublon.',
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être un texte.',
    'email' => 'Veuillez saisir une adresse email valide.',
    'unique' => 'Cette valeur pour :attribute est déjà utilisée.',
    'confirmed' => 'La confirmation de :attribute ne correspond pas.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'array' => 'Le contenu de :attribute est invalide.',
    'in' => 'La valeur de :attribute n’est pas autorisée.',
    'uuid' => 'La référence de la demande est invalide.',
    'date_format' => 'Le champ :attribute doit respecter le format :format.',
    'after_or_equal' => 'Le champ :attribute doit être postérieur ou égal à :date.',
    'before_or_equal' => 'Le champ :attribute doit être antérieur ou égal à :date.',
    'min' => ['string' => 'Le champ :attribute doit contenir au moins :min caractères.', 'numeric' => 'Le champ :attribute doit être supérieur ou égal à :min.', 'array' => 'Ajoutez au moins :min élément(s).'],
    'max' => ['string' => 'Le champ :attribute ne doit pas dépasser :max caractères.', 'numeric' => 'Le champ :attribute ne doit pas dépasser :max.', 'array' => 'Vous ne pouvez pas ajouter plus de :max éléments.'],
    'attributes' => ['name' => 'nom', 'email' => 'email', 'password' => 'mot de passe', 'company' => 'entreprise', 'contact_id' => 'client', 'issue_date' => 'date de facture', 'due_date' => 'échéance', 'paid_at' => 'date du règlement', 'amount' => 'montant', 'price' => 'prix', 'items' => 'lignes de facture', 'tax_basis_points' => 'taux de taxe', 'comment' => 'motif', 'permissions' => 'permissions', 'threshold' => 'seuil', 'steps' => 'étapes', 'role' => 'rôle'],
];
