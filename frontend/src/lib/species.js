// The species the shelter takes in, as [value, label]. Mirrors App\Models\Animal::SPECIES;
// values are stored lowercase, which the Matchmaker filters on.
export const SPECIES = [['dog', 'Dog'], ['cat', 'Cat']];

export const isKnownSpecies = (value) => SPECIES.some(([v]) => v === value);
