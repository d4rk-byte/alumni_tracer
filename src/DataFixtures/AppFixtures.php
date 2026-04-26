<?php

namespace App\DataFixtures;

use App\Entity\College;
use App\Entity\Department;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    /**
     * @var array<string, array{name: string, description: string}>
     */
    private const COLLEGES = [
        'CAS' => [
            'name' => 'College of Arts & Sciences',
            'description' => 'Programs in humanities, social sciences, mathematics, and natural sciences.',
        ],
        'CBA' => [
            'name' => 'College of Business Administration',
            'description' => 'Programs in business, hospitality, and tourism management.',
        ],
        'COED' => [
            'name' => 'College of Education',
            'description' => 'Programs for teacher education and academic leadership.',
        ],
        'CEA' => [
            'name' => 'College of Engineering & Architecture',
            'description' => 'Programs in engineering and design disciplines.',
        ],
        'CIT' => [
            'name' => 'College of Information Technology',
            'description' => 'Programs in computing, information systems, and digital innovation.',
        ],
        'CNAHS' => [
            'name' => 'College of Nursing & Allied Health Sciences',
            'description' => 'Programs in nursing and allied health professions.',
        ],
        'CA' => [
            'name' => 'College of Agriculture',
            'description' => 'Programs in agriculture, fisheries, and related sciences.',
        ],
        'CCJE' => [
            'name' => 'College of Criminal Justice Education',
            'description' => 'Programs in criminology and public safety.',
        ],
        'CINDTECH' => [
            'name' => 'College of Industrial Technology',
            'description' => 'Programs in industrial and applied technology.',
        ],
    ];

    /**
     * @var list<array{college: string, code: string, name: string, description: string}>
     */
    private const DEPARTMENTS = [
        ['college' => 'CAS', 'code' => 'ABENGLISH', 'name' => 'English', 'description' => 'Department for English language and literature studies.'],
        ['college' => 'CAS', 'code' => 'ABPOLSCI', 'name' => 'Political Science', 'description' => 'Department for political science and governance studies.'],
        ['college' => 'CAS', 'code' => 'BSBIO', 'name' => 'Biology', 'description' => 'Department for biological sciences.'],
        ['college' => 'CAS', 'code' => 'BSMATH', 'name' => 'Mathematics', 'description' => 'Department for mathematics and quantitative studies.'],
        ['college' => 'CAS', 'code' => 'BSES', 'name' => 'Environmental Science', 'description' => 'Department for environmental science and sustainability studies.'],
        ['college' => 'CBA', 'code' => 'BSA', 'name' => 'Accountancy', 'description' => 'Department for accountancy and financial reporting.'],
        ['college' => 'CBA', 'code' => 'BSBA', 'name' => 'Business Administration', 'description' => 'Department for business administration and management.'],
        ['college' => 'CBA', 'code' => 'BSHM', 'name' => 'Hospitality Management', 'description' => 'Department for hospitality operations and service management.'],
        ['college' => 'CBA', 'code' => 'BSTM', 'name' => 'Tourism Management', 'description' => 'Department for tourism and destination management.'],
        ['college' => 'COED', 'code' => 'BEED', 'name' => 'Elementary Education', 'description' => 'Department for elementary teacher education.'],
        ['college' => 'COED', 'code' => 'BSED', 'name' => 'Secondary Education', 'description' => 'Department for secondary teacher education.'],
        ['college' => 'COED', 'code' => 'BPED', 'name' => 'Physical Education', 'description' => 'Department for physical education and sports pedagogy.'],
        ['college' => 'COED', 'code' => 'BSNED', 'name' => 'Special Needs Education', 'description' => 'Department for inclusive and special needs education.'],
        ['college' => 'CEA', 'code' => 'BSCE', 'name' => 'Civil Engineering', 'description' => 'Department for civil engineering.'],
        ['college' => 'CEA', 'code' => 'BSEE', 'name' => 'Electrical Engineering', 'description' => 'Department for electrical engineering.'],
        ['college' => 'CEA', 'code' => 'BSME', 'name' => 'Mechanical Engineering', 'description' => 'Department for mechanical engineering.'],
        ['college' => 'CEA', 'code' => 'BSARCH', 'name' => 'Architecture', 'description' => 'Department for architecture and built environment studies.'],
        ['college' => 'CIT', 'code' => 'BSIT', 'name' => 'Information Technology', 'description' => 'Department for information technology.'],
        ['college' => 'CIT', 'code' => 'BSCS', 'name' => 'Computer Science', 'description' => 'Department for computer science.'],
        ['college' => 'CIT', 'code' => 'BSIS', 'name' => 'Information Systems', 'description' => 'Department for information systems and business technology.'],
        ['college' => 'CNAHS', 'code' => 'BSN', 'name' => 'Nursing', 'description' => 'Department for nursing education.'],
        ['college' => 'CNAHS', 'code' => 'BSMID', 'name' => 'Midwifery', 'description' => 'Department for midwifery and maternal care.'],
        ['college' => 'CA', 'code' => 'BSAGRI', 'name' => 'Agriculture', 'description' => 'Department for agriculture and crop science.'],
        ['college' => 'CA', 'code' => 'BSFISH', 'name' => 'Fisheries', 'description' => 'Department for fisheries and aquatic sciences.'],
        ['college' => 'CCJE', 'code' => 'BSCRIM', 'name' => 'Criminology', 'description' => 'Department for criminology and criminal justice studies.'],
        ['college' => 'CINDTECH', 'code' => 'BSINDTECH', 'name' => 'Industrial Technology', 'description' => 'Department for industrial and applied technology.'],
    ];

    public function __construct(
        private CollegeRepository $collegeRepository,
        private DepartmentRepository $departmentRepository,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $collegesByCode = [];
        foreach ($this->collegeRepository->findAll() as $college) {
            $collegesByCode[$college->getCode()] = $college;
        }

        foreach (self::COLLEGES as $code => $data) {
            $college = $collegesByCode[$code] ?? new College();
            $college
                ->setCode($code)
                ->setName($data['name'])
                ->setDescription($data['description'])
                ->setIsActive(true);

            if (!isset($collegesByCode[$code])) {
                $manager->persist($college);
                $collegesByCode[$code] = $college;
            }
        }

        $departmentsByCode = [];
        foreach ($this->departmentRepository->findAll() as $department) {
            $departmentsByCode[$department->getCode()] = $department;
        }

        foreach (self::DEPARTMENTS as $data) {
            $college = $collegesByCode[$data['college']] ?? null;

            if (!$college instanceof College) {
                continue;
            }

            $department = $departmentsByCode[$data['code']] ?? new Department();
            $department
                ->setCode($data['code'])
                ->setName($data['name'])
                ->setDescription($data['description'])
                ->setCollege($college)
                ->setIsActive(true);

            if (!isset($departmentsByCode[$data['code']])) {
                $manager->persist($department);
                $departmentsByCode[$data['code']] = $department;
            }
        }

        $manager->flush();
    }
}
