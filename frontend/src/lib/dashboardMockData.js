export const dashboardMock = {
  user: {
    name: 'Volunteer User',
    email: 'volunteer@example.com',
    role: 'user',
    contact: '0917 000 0000',
  },
  admin: {
    name: 'Admin',
    email: 'admin@example.com',
    role: 'admin',
  },
  stats: {
    admin: [
      { key: 'dogsInCare', icon: '🐾', variant: 'green', label: 'Dogs in care', value: 148, sub: '+12 this month' },
      { key: 'adoptedThisYear', icon: '🏠', variant: 'green', label: 'Adopted this year', value: 86, sub: '+14 vs last year' },
      { key: 'ready', icon: '🐾', variant: 'blue', label: 'Ready for adoption', value: 32, sub: 'All aspins' },
      { key: 'donationsMonth', icon: '💰', variant: 'amber', label: 'Donations (month)', value: '₱42k', sub: '+18% vs last month' },
    ],
    user: [
      { key: 'myApplications', icon: '📄', variant: 'purple', label: 'My applications', value: 6, sub: 'Total requests' },
      { key: 'approved', icon: '✅', variant: 'green', label: 'Approved', value: 2, sub: 'Ready to proceed' },
      { key: 'pending', icon: '⏳', variant: 'sky', label: 'Pending', value: 3, sub: 'Waiting for approval' },
      { key: 'rejected', icon: '⛔', variant: 'orange', label: 'Rejected', value: 1, sub: 'Try again later' },
    ],
  },
  analyticsBars: [
    { label: 'Ready for adoption', value: 65, count: 48, color: 'green' },
    { label: 'Under treatment', value: 42, count: 31, color: 'red' },
    { label: 'Foster care', value: 30, count: 22, color: 'sky' },
    { label: 'Adopted', value: 51, count: 38, color: 'green-m' },
    { label: 'Transferred', value: 12, count: 9, color: 'muted' },
  ],
  recentlyAdopted: [
    { dog: 'Choco (Aspin)', adopter: 'Maria Santos', status: 'Adopted', variant: 'green' },
    { dog: 'Rex (Aspin)', adopter: 'Carlo Bautista', status: 'Adopted', variant: 'green' },
    { dog: 'Biscuit (Aspin)', adopter: 'Lea Mendoza', status: 'Pending', variant: 'amber' },
    { dog: 'Duke (Aspin)', adopter: 'Juan dela Cruz', status: 'Foster', variant: 'blue' },
    { dog: 'Luna (Aspin)', adopter: 'Ana Reyes', status: 'Pending', variant: 'amber' },
  ],
  medicalReminders: [
    { dog: 'Choco', id: '#0041', type: 'Rabies vaccine', dueDate: 'Apr 2, 2026', status: 'Due soon', variant: 'amber' },
    { dog: 'Bruno', id: '#0047', type: 'Deworming', dueDate: 'Apr 5, 2026', status: 'Due soon', variant: 'amber' },
    { dog: 'Coco', id: '#0045', type: 'Checkup', dueDate: 'Mar 31, 2026', status: 'Urgent', variant: 'red' },
    { dog: 'Max', id: '#0049', type: 'Booster shot', dueDate: 'Apr 10, 2026', status: 'Scheduled', variant: 'green' },
  ],
  recentlyAddedAnimals: [
    {
      id: 1,
      name: 'Brown Aspin',
      species: 'Dog',
      age: '8 months',
      status: 'Ready for adoption',
      photo: '/mock datas/image/brown_aspin_dog_480x480.jpg',
    },
    {
      id: 2,
      name: 'Doggie',
      species: 'Dog',
      age: '1 year',
      status: 'In foster care',
      photo: '/mock datas/image/doggie.jfif',
    },
    {
      id: 3,
      name: 'Doggie Two',
      species: 'Dog',
      age: '2 years',
      status: 'Medical recovery',
      photo: '/mock datas/image/doggie2.jpg',
    },
    {
      id: 4,
      name: 'Doggie Three',
      species: 'Dog',
      age: '6 months',
      status: 'Available for adoption',
      photo: '/mock datas/image/doggie3.jpg',
    },
  ],
};

