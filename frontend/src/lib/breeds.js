// Common breeds offered as suggestions in the breed field. Suggestions only — breed stays free
// text, so staff can still type a breed (or a mix) that isn't listed. The local breeds the
// shelter mostly takes in come first; the rest are alphabetical.
const DOG_BREEDS = [
  'Aspin',
  'Aspin mix',
  'Akita',
  'Alaskan Malamute',
  'American Bully',
  'Beagle',
  'Belgian Malinois',
  'Bichon Frise',
  'Border Collie',
  'Boxer',
  'Chihuahua',
  'Chow Chow',
  'Cocker Spaniel',
  'Corgi',
  'Dachshund',
  'Dalmatian',
  'Doberman Pinscher',
  'English Bulldog',
  'French Bulldog',
  'German Shepherd',
  'Golden Retriever',
  'Great Dane',
  'Jack Russell Terrier',
  'Japanese Spitz',
  'Labrador Retriever',
  'Lhasa Apso',
  'Maltese',
  'Miniature Pinscher',
  'Miniature Schnauzer',
  'Pekingese',
  'Pit Bull Terrier',
  'Pomeranian',
  'Poodle',
  'Pug',
  'Rottweiler',
  'Saint Bernard',
  'Samoyed',
  'Shiba Inu',
  'Shih Tzu',
  'Shih Tzu mix',
  'Siberian Husky',
  'Yorkshire Terrier',
  'Mixed breed',
];

const CAT_BREEDS = [
  'Puspin',
  'Puspin mix',
  'Abyssinian',
  'American Shorthair',
  'Bengal',
  'British Shorthair',
  'Burmese',
  'Domestic Longhair',
  'Domestic Shorthair',
  'Exotic Shorthair',
  'Himalayan',
  'Maine Coon',
  'Munchkin',
  'Norwegian Forest Cat',
  'Persian',
  'Ragdoll',
  'Russian Blue',
  'Scottish Fold',
  'Siamese',
  'Sphynx',
  'Turkish Angora',
  'Mixed breed',
];

/**
 * The suggestion groups for a species ('dog' / 'cat'); both groups when it isn't chosen yet.
 * @returns {{ label: string, breeds: string[] }[]}
 */
export function breedGroups(species) {
  if (species === 'dog') return [{ label: 'Dog breeds', breeds: DOG_BREEDS }];
  if (species === 'cat') return [{ label: 'Cat breeds', breeds: CAT_BREEDS }];
  return [
    { label: 'Dog breeds', breeds: DOG_BREEDS },
    { label: 'Cat breeds', breeds: CAT_BREEDS },
  ];
}
