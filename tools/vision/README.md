# Reconnaissance d'image de Maillage

La recherche par photo (`maillage/explorer/#/photo`) reconnaît le produit **dans le navigateur du client** :
aucun service extérieur, aucune clé, aucun coût par photo. Fichiers servis : `maillage/assets/vision/`
(≈ 37 Mo, téléchargés à la première recherche par photo puis gardés un mois par le navigateur) et
`maillage/assets/vision.js`.

Fonctionnement : le modèle d'image (TinyCLIP, Microsoft, **licence MIT**, utilisable commercialement) transforme la
photo en un vecteur, comparé aux vecteurs de texte de `vocab.py` (types de produits en français et en arabe avec
leur rayon, marques, couleurs), calculés ici une fois pour toutes. La couleur proposée doit en plus s'accorder avec
les pixels du centre de la photo. Tout est présenté au client comme une proposition à vérifier.

Ne pas remplacer le modèle par MobileCLIP (Apple) : sa licence réserve les poids à la recherche non commerciale.

## Modifier le vocabulaire ou reconstruire

```bash
pip install onnx onnx_ir onnxruntime tokenizers numpy pillow
```

```bash
python3 tools/vision/build.py --test photo1.jpg photo2.jpg
```

Puis augmenter `VERSION` dans `maillage/assets/vision.js` pour que les navigateurs rechargent les fichiers.

## Limites

- Reconnaît le **type** de produit (chemise, bouteille d'eau, marque-page…), pas le modèle exact ni, en pratique,
  la marque : le client la saisit.
- Un produit mal cadré ou peu courant n'obtient que le rayon, avec des possibilités à choisir en un geste.
